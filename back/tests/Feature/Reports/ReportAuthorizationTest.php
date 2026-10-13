<?php

namespace Tests\Feature\Reports;

use App\Domain\Users\Enums\RoleCode;
use App\Models\AuditLog;
use App\Models\Event;
use App\Models\Permission;
use App\Models\Sector;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\DatabaseTestCase;

/**
 * Relatórios: só reports.view (Administrador e Consultor); demais perfis 403; visitante 401; leitura não audita.
 */
class ReportAuthorizationTest extends DatabaseTestCase
{
    public const TYPES = ['general', 'participants-presence', 'operations', 'closing-results'];

    /**
     * @return array<string, array{string}>
     */
    public static function types(): array
    {
        return array_combine(self::TYPES, array_map(fn ($type) => [$type], self::TYPES));
    }

    #[DataProvider('types')]
    public function test_only_admin_and_consultant_can_read(string $type): void
    {
        $event = Event::factory()->create();
        $url = "/api/v1/events/{$event->id}/reports/{$type}";

        foreach ([RoleCode::Administrator, RoleCode::Consultant] as $role) {
            $this->actingAs($this->userWithRole($role))->getJson($url)->assertOk()
                ->assertJsonPath('data.type', $type)
                ->assertJsonPath('data.event.id', $event->id)
                ->assertJsonPath('data.generated_by.role.code', $role->value);
        }

        foreach ([RoleCode::Manager, RoleCode::Editor, RoleCode::Juror, RoleCode::Voter] as $role) {
            $user = in_array($role, [RoleCode::Manager, RoleCode::Editor], true)
                ? $this->sectorUser($role, Sector::factory()->for($event)->create())
                : $this->userWithRole($role);
            $this->actingAs($user)->getJson($url)->assertForbidden();
        }
    }

    #[DataProvider('types')]
    public function test_guest_gets_401_and_unknown_event_404(string $type): void
    {
        $event = Event::factory()->create();
        $this->getJson("/api/v1/events/{$event->id}/reports/{$type}")->assertUnauthorized();
        $this->actingAs($this->admin())->getJson("/api/v1/events/999999/reports/{$type}")->assertNotFound();
    }

    public function test_reading_reports_does_not_create_audit_logs(): void
    {
        $event = Event::factory()->create();
        $admin = $this->admin();
        $before = AuditLog::query()->count();

        foreach (self::TYPES as $type) {
            $this->actingAs($admin)->getJson("/api/v1/events/{$event->id}/reports/{$type}")->assertOk();
        }

        $this->assertSame($before, AuditLog::query()->count());
    }

    public function test_metadata_comes_from_backend_and_authenticated_user(): void
    {
        $event = Event::factory()->create(['name' => 'Hackathon Relatório', 'timezone' => 'America/Sao_Paulo']);
        $admin = $this->admin();

        $data = $this->actingAs($admin)->getJson("/api/v1/events/{$event->id}/reports/general")->assertOk()->json('data');

        $this->assertSame('Relatório Geral do Hackathon', $data['title']);
        $this->assertSame($admin->id, $data['generated_by']['id']);
        $this->assertSame($admin->person->full_name, $data['generated_by']['name']);
        $this->assertSame('Administrador', $data['generated_by']['role']['name']);
        $this->assertNotFalse(\DateTimeImmutable::createFromFormat(DATE_ATOM, $data['generated_at']));
        $this->assertSame('Hackathon Relatório', $data['event']['name']);
        $this->assertSame('America/Sao_Paulo', $data['event']['timezone']);
    }

    public function test_permission_appears_in_auth_me_and_roles_only_for_admin_and_consultant(): void
    {
        foreach ([RoleCode::Administrator, RoleCode::Consultant] as $role) {
            $this->assertContains('reports.view', $this->actingAs($this->userWithRole($role))->getJson('/api/v1/auth/me')->json('data.permissions'));
        }
        foreach ([RoleCode::Juror, RoleCode::Voter] as $role) {
            $this->assertNotContains('reports.view', $this->actingAs($this->userWithRole($role))->getJson('/api/v1/auth/me')->json('data.permissions'));
        }

        $roles = collect($this->actingAs($this->admin())->getJson('/api/v1/roles')->assertOk()->json('data'))
            ->filter(fn ($role) => in_array('reports.view', $role['permissions'], true))->pluck('code')->sort()->values()->all();
        $this->assertSame(['ADMINISTRATOR', 'CONSULTANT'], $roles);
    }

    public function test_role_permission_seeder_stays_idempotent(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $this->seed(RolePermissionSeeder::class);

        $this->assertSame(1, Permission::query()->where('code', 'reports.view')->count());
        $this->assertSame(2, DB::table('role_permission')
            ->join('permissions', 'permissions.id', '=', 'role_permission.permission_id')
            ->where('permissions.code', 'reports.view')->count());
    }
}
