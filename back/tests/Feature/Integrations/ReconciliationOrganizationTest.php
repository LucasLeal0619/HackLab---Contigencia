<?php

namespace Tests\Feature\Integrations;

use App\Domain\Audit\AuditAction;
use App\Domain\Auth\Enums\AccessGrantStatus;
use App\Domain\Users\Enums\PermissionCode;
use App\Domain\Users\Enums\RoleCode;
use App\Models\AccountAccessGrant;
use App\Models\AuditLog;
use App\Models\Event;
use App\Models\Role;
use App\Models\Sector;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ReconciliationScenario;
use Tests\DatabaseTestCase;

/**
 * Fila Organização: ORGANIZATION não cria User. O Administrador libera o acesso (perfil/setor), sem senha.
 */
class ReconciliationOrganizationTest extends DatabaseTestCase
{
    use ReconciliationScenario;

    private Sector $sector;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->buildReconciliationScenario();
        $this->sector = Sector::factory()->for($this->event)->create(['name' => 'Infraestrutura']);
    }

    private function grant(int $personId, array $body, ?User $user = null)
    {
        return $this->actingAs($user ?? $this->admin())->postJson($this->base()."/reconciliation/organization/{$personId}/grant", $body);
    }

    public function test_organization_registration_is_a_candidate_and_never_creates_a_user(): void
    {
        $person = $this->candidate('ana@teste.local', 'organization', ['full_name' => 'Ana Souza']);

        $this->actingAs($this->admin())->getJson($this->base().'/reconciliation/organization')
            ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.person.full_name', 'Ana Souza')
            ->assertJsonPath('data.0.tickets', ['Organização'])->assertJsonPath('data.0.internal.user', null);

        $this->assertSame(0, User::query()->where('person_id', $person->id)->count());
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function allowedRoles(): array
    {
        return ['Gestor com setor' => ['MANAGER', true], 'Editor com setor' => ['EDITOR', true], 'Consultor sem setor' => ['CONSULTANT', false]];
    }

    #[DataProvider('allowedRoles')]
    public function test_admin_grants_access_with_role_and_sector(string $role, bool $withSector): void
    {
        $person = $this->candidate('ana@teste.local', 'organization');

        $this->grant($person->id, ['role' => $role, 'sector_id' => $withSector ? $this->sector->id : null])
            ->assertCreated()->assertJsonPath('data.status', 'PENDING')->assertJsonPath('data.role.code', $role)
            ->assertJsonPath('data.sector.id', $withSector ? $this->sector->id : null);

        $grant = AccountAccessGrant::query()->sole();
        $this->assertSame('EVEN3_ORGANIZATION', $grant->source->value);
        $this->assertSame(0, User::query()->where('person_id', $person->id)->count(), 'liberar não cria conta');
        $this->assertTrue(AuditLog::query()->where('action', AuditAction::ACCESS_GRANT_CREATED)->exists());
        $this->actingAs($this->admin())->getJson($this->base().'/reconciliation/organization')->assertJsonPath('meta.total', 0);
        $this->actingAs($this->admin())->getJson($this->base().'/reconciliation/organization?view=all')
            ->assertJsonPath('data.0.state', 'DONE')->assertJsonPath('data.0.internal.grant.role', $role);
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function refusedGrants(): array
    {
        return [
            'Gestor sem setor' => [['role' => 'MANAGER'], 'sector_id'],
            'Editor sem setor' => [['role' => 'EDITOR'], 'sector_id'],
            'Consultor com setor' => [['role' => 'CONSULTANT', 'sector_id' => 'SETOR'], 'sector_id'],
            'Administrador' => [['role' => 'ADMINISTRATOR'], 'role'],
            'Jurado' => [['role' => 'JUROR'], 'role'],
            'Votante' => [['role' => 'VOTER'], 'role'],
            'com senha' => [['role' => 'CONSULTANT', 'password' => 'Senha12345'], 'password'],
        ];
    }

    /**
     * @param  array<string, mixed>  $body
     */
    #[DataProvider('refusedGrants')]
    public function test_refuses_invalid_grants(array $body, string $field): void
    {
        $person = $this->candidate('ana@teste.local', 'organization');
        if (($body['sector_id'] ?? null) === 'SETOR') {
            $body['sector_id'] = $this->sector->id;
        }

        $this->grant($person->id, $body)->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertSame(0, AccountAccessGrant::query()->count());
    }

    public function test_refuses_sector_of_another_event_or_inactive(): void
    {
        $person = $this->candidate('ana@teste.local', 'organization');
        $foreign = Sector::factory()->for(Event::factory())->create();
        $inactive = Sector::factory()->for($this->event)->inactive()->create();

        $this->grant($person->id, ['role' => 'MANAGER', 'sector_id' => $foreign->id])->assertUnprocessable()->assertJsonValidationErrors('sector_id');
        $this->grant($person->id, ['role' => 'EDITOR', 'sector_id' => $inactive->id])->assertUnprocessable()->assertJsonValidationErrors('sector_id');
        $this->assertSame(0, AccountAccessGrant::query()->count());
    }

    public function test_person_with_account_does_not_receive_a_grant(): void
    {
        $person = $this->candidate('ana@teste.local', 'organization');
        User::factory()->role(RoleCode::Consultant)->create(['person_id' => $person->id, 'email' => 'ana@teste.local']);

        $this->actingAs($this->admin())->getJson($this->base().'/reconciliation/organization?view=all')
            ->assertJsonPath('data.0.state', 'DONE')->assertJsonPath('data.0.internal.user.role', 'CONSULTANT');
        $this->grant($person->id, ['role' => 'MANAGER', 'sector_id' => $this->sector->id])->assertUnprocessable()->assertJsonValidationErrors('person');
    }

    public function test_one_pending_grant_per_person_and_revoke_brings_back_the_candidate(): void
    {
        $person = $this->candidate('ana@teste.local', 'organization');
        $admin = $this->admin();

        $grantId = $this->grant($person->id, ['role' => 'CONSULTANT'], $admin)->assertCreated()->json('data.id');
        $this->grant($person->id, ['role' => 'MANAGER', 'sector_id' => $this->sector->id], $admin)->assertUnprocessable();

        $this->actingAs($admin)->postJson("/api/v1/account-access-grants/{$grantId}/revoke")->assertOk()->assertJsonPath('data.status', 'REVOKED');
        $this->assertSame(AccessGrantStatus::Revoked, AccountAccessGrant::query()->find($grantId)->status);
        $this->assertTrue(AuditLog::query()->where('action', AuditAction::ACCESS_GRANT_REVOKED)->exists());
        $this->actingAs($admin)->getJson($this->base().'/reconciliation/organization')->assertJsonPath('meta.total', 1);

        // Revogar de novo: nada a revogar.
        $this->actingAs($admin)->postJson("/api/v1/account-access-grants/{$grantId}/revoke")->assertUnprocessable();
        // Depois da revogação, pode liberar outra.
        $this->grant($person->id, ['role' => 'MANAGER', 'sector_id' => $this->sector->id], $admin)->assertCreated();
    }

    public function test_ignore_and_restore(): void
    {
        $person = $this->candidate('ana@teste.local', 'organization');
        $admin = $this->admin();

        $this->actingAs($admin)->postJson($this->base()."/reconciliation/organization/{$person->id}/ignore")->assertOk();
        $this->grant($person->id, ['role' => 'CONSULTANT'], $admin)->assertUnprocessable();
        $this->actingAs($admin)->postJson($this->base()."/reconciliation/organization/{$person->id}/restore")->assertOk();
        $this->grant($person->id, ['role' => 'CONSULTANT'], $admin)->assertCreated();
    }

    public function test_requires_users_manage(): void
    {
        $person = $this->candidate('ana@teste.local', 'organization');
        $other = $this->candidate('bia@teste.local', 'organization');
        // Liberação criada antes de retirar users.manage do perfil Administrador (a retirada vale para todos).
        $grant = $this->grant($other->id, ['role' => 'CONSULTANT'])->assertCreated()->json('data.id');
        $user = $this->adminWithout(PermissionCode::UsersManage);

        $this->actingAs($user)->getJson($this->base().'/reconciliation/organization')->assertForbidden();
        $this->grant($person->id, ['role' => 'CONSULTANT'], $user)->assertForbidden();
        $this->actingAs($user)->postJson($this->base()."/reconciliation/organization/{$person->id}/ignore")->assertForbidden();

        $this->actingAs($user)->postJson("/api/v1/account-access-grants/{$grant}/revoke")->assertForbidden();
        $this->actingAs($this->userWithRole(RoleCode::Consultant))->postJson("/api/v1/account-access-grants/{$grant}/revoke")->assertForbidden();
    }

    public function test_database_refuses_forbidden_roles_and_two_pending_grants(): void
    {
        $person = $this->candidate('ana@teste.local', 'organization');
        $admin = $this->admin();
        $insert = fn (string $role, ?int $sector) => \DB::table('account_access_grants')->insert([
            'event_id' => $this->event->id, 'person_id' => $person->id, 'role_id' => Role::forCode(RoleCode::from($role))->id,
            'sector_id' => $sector, 'status' => 'PENDING', 'source' => 'EVEN3_ORGANIZATION', 'granted_by_user_id' => $admin->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        foreach ([['ADMINISTRATOR', null], ['JUROR', null], ['MANAGER', null], ['CONSULTANT', $this->sector->id]] as [$role, $sector]) {
            try {
                \DB::transaction(fn () => $insert($role, $sector));
                $this->fail("O banco aceitou {$role}");
            } catch (QueryException $e) {
                $this->assertSame('23514', $e->getCode(), $role);
            }
        }

        $insert('CONSULTANT', null);
        $this->expectException(UniqueConstraintViolationException::class);
        $insert('EDITOR', $this->sector->id);
    }
}
