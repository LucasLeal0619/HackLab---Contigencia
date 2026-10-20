<?php

namespace Tests\Feature\Integrations;

use App\Domain\Integrations\Enums\RegistrationStatus;
use App\Domain\Integrations\Enums\SyncScope;
use App\Domain\Integrations\Enums\SyncStatus;
use App\Domain\Integrations\Enums\SyncTrigger;
use App\Domain\Integrations\Enums\TicketCategory;
use App\Domain\Users\Enums\RoleCode;
use App\Models\AuditLog;
use App\Models\Event;
use App\Models\IntegrationSyncRun;
use App\Models\Juror;
use App\Models\Participant;
use App\Models\Sector;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\ReconciliationScenario;
use Tests\DatabaseTestCase;

/**
 * Resumo do Centro Even3: só contagens por Person, do PostgreSQL. Nunca chama a Even3, não audita,
 * não devolve dados pessoais nem segredo.
 */
class Even3WorkbenchTest extends DatabaseTestCase
{
    use ReconciliationScenario;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildReconciliationScenario();
    }

    private function workbench(?User $user = null): array
    {
        return $this->actingAs($user ?? $this->admin())->getJson($this->base().'/workbench')->assertOk()->json('data');
    }

    public function test_event_without_integration_reports_not_configured(): void
    {
        $other = Event::factory()->create();

        $data = $this->actingAs($this->admin())->getJson("/api/v1/events/{$other->id}/integrations/even3/workbench")->assertOk()->json('data');

        $this->assertFalse($data['configured']);
        $this->assertNull($data['pending']);
        $this->assertFalse($data['sync']['running']);
    }

    public function test_counts_are_per_person_and_only_trusted_registrations(): void
    {
        // Jurado: duas inscrições da mesma Person contam uma vez.
        $twice = $this->candidate('jurado@teste.local', 'juror');
        $this->inscribe($twice, 'juror');
        // Não entram: conflito de identidade, pendente, cancelada, entrada sem mapeamento ("Jurado" só no nome).
        $this->inscribe($this->person('conflito@teste.local'), 'juror', attributes: ['identity_mismatch' => true]);
        $this->inscribe($this->person('pendente@teste.local'), 'juror', RegistrationStatus::Pending);
        $this->inscribe($this->person('cancelada@teste.local'), 'juror', RegistrationStatus::Cancelled);
        $this->inscribe($this->person('semmapa@teste.local'), 'juror_unmapped');
        // Já é jurado do evento (inativo): não é pendência.
        $already = $this->candidate('jajurado@teste.local', 'juror');
        Juror::factory()->for($this->event)->create(['person_id' => $already->id, 'status' => 'INACTIVE']);

        $this->candidate('org1@teste.local', 'organization');
        $this->candidate('org2@teste.local', 'organization');
        $this->candidate('empresa@teste.local', 'company');
        $this->candidate('publico1@teste.local', 'public');
        $publicWithAccount = $this->candidate('publico2@teste.local', 'public');
        User::factory()->role(RoleCode::Voter)->create(['person_id' => $publicWithAccount->id, 'email' => 'publico2@teste.local']);
        $this->candidate('part1@teste.local', 'participant');
        $part2 = $this->candidate('part2@teste.local', 'participant');
        Participant::factory()->for($this->event)->create(['person_id' => $part2->id]);

        // Outro evento: nada dele entra.
        $other = Event::factory()->create();
        $otherIntegration = $this->even3Integration($other, 'outro');
        $this->tickets['other_juror'] = $this->ticketType($otherIntegration, '99', 'Jurado', TicketCategory::Juror);
        $this->inscribe($this->person('outro@teste.local'), 'other_juror', integration: $otherIntegration);

        $data = $this->workbench();

        $this->assertTrue($data['configured']);
        $this->assertSame([
            'participants' => 1,
            'jurors' => 1,
            'organization' => 2,
            'company_guests' => 1,
            'public_without_access' => 1,
            'event_differences' => 0,
        ], $data['pending']);
        $this->assertSame(['jurors' => 0, 'organization' => 0, 'company_guests' => 0], $data['ignored']);
        $this->assertSame(2, $data['unmapped_tickets']); // "Público" e "Jurado convidado" sem mapeamento
    }

    public function test_reports_last_sync_and_running_without_calling_even3(): void
    {
        Http::fake(); // qualquer chamada falharia o preventStrayRequests; nenhuma deve acontecer
        $this->integration->forceFill(['last_sync_at' => now()->subHour(), 'last_sync_status' => SyncStatus::Partial, 'last_error_code' => null])->save();
        $run = new IntegrationSyncRun(['event_integration_id' => $this->integration->id, 'scope' => SyncScope::All, 'trigger' => SyncTrigger::Manual, 'started_at' => now()]);
        $run->forceFill(['status' => SyncStatus::Running, 'counters' => []])->save();

        $data = $this->workbench();

        $this->assertTrue($data['sync']['running']);
        $this->assertNotNull($data['sync']['running_since']);
        $this->assertSame('PARTIAL', $data['sync']['last_sync_status']);
        Http::assertNothingSent();
    }

    public function test_stale_running_is_not_reported_as_running_and_failed_status_is_exposed(): void
    {
        $this->integration->forceFill(['last_sync_at' => now(), 'last_sync_status' => SyncStatus::Failed, 'last_error_code' => 'UNAUTHORIZED'])->save();
        $run = new IntegrationSyncRun(['event_integration_id' => $this->integration->id, 'scope' => SyncScope::All, 'trigger' => SyncTrigger::Manual, 'started_at' => now()->subHours(2)]);
        $run->forceFill(['status' => SyncStatus::Running, 'counters' => []])->save();

        $data = $this->workbench();

        $this->assertFalse($data['sync']['running']);
        $this->assertSame('FAILED', $data['sync']['last_sync_status']);
        $this->assertSame('UNAUTHORIZED', $data['sync']['last_error_code']);
    }

    public function test_consultant_reads_and_other_profiles_are_forbidden(): void
    {
        $this->candidate('jurado@teste.local', 'juror');

        $this->assertSame(1, $this->workbench($this->userWithRole(RoleCode::Consultant))['pending']['jurors']);

        $sector = Sector::factory()->for($this->event)->create();
        foreach ([$this->sectorUser(RoleCode::Manager, $sector), $this->sectorUser(RoleCode::Editor, $sector), $this->userWithRole(RoleCode::Juror), $this->userWithRole(RoleCode::Voter)] as $user) {
            $this->actingAs($user)->getJson($this->base().'/workbench')->assertForbidden();
        }

        $this->app['auth']->forgetGuards();
        $this->getJson($this->base().'/workbench')->assertUnauthorized();
    }

    public function test_exposes_no_personal_data_no_secret_and_does_not_audit(): void
    {
        $this->candidate('sigilo@teste.local', 'juror', ['full_name' => 'Pessoa Sigilosa']);
        $logs = AuditLog::query()->count();

        $json = $this->actingAs($this->admin())->getJson($this->base().'/workbench')->assertOk()->getContent();

        $this->assertStringNotContainsString('sigilo@teste.local', $json);
        $this->assertStringNotContainsString('Pessoa Sigilosa', $json);
        $this->assertStringNotContainsString(self::EVEN3_TOKEN, $json);
        $this->assertStringNotContainsString(self::EVEN3_WEBHOOK_SECRET, $json);
        $this->assertStringNotContainsString('token', strtolower($json));
        $this->assertSame($logs, AuditLog::query()->count());
    }
}
