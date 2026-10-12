<?php

namespace Tests\Feature\Integrations;

use App\Domain\Audit\AuditAction;
use App\Domain\Integrations\Enums\TicketCategory;
use App\Domain\Users\Enums\RoleCode;
use App\Models\AuditLog;
use App\Models\Event;
use App\Models\EventIntegration;
use App\Models\ExternalTicketType;
use App\Models\Sector;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\Even3Fakes;
use Tests\DatabaseTestCase;

/**
 * Vínculo explícito Event ↔ evento Even3 (conferido na API), status sem segredos, entradas (descoberta,
 * renomeação, desativação) e mapeamento manual por external_ticket_id.
 */
class Even3IntegrationConfigTest extends DatabaseTestCase
{
    use Even3Fakes;

    private Event $event;

    private User $adminUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureEven3();
        $this->event = Event::factory()->create(['name' => 'Hackathon Teste', 'start_date' => '2026-11-04', 'end_date' => '2026-11-06']);
        $this->adminUser = $this->admin();
    }

    private function configure(array $payload = ['external_event_id' => '793572', 'status' => 'ACTIVE'], ?Event $event = null, ?User $user = null)
    {
        return $this->actingAs($user ?? $this->adminUser)->putJson('/api/v1/events/'.($event ?? $this->event)->id.'/integrations/even3', $payload);
    }

    private function sync(string $scope = 'ALL')
    {
        return $this->actingAs($this->adminUser)->postJson("/api/v1/events/{$this->event->id}/integrations/even3/sync", ['scope' => $scope]);
    }

    public function test_admin_configures_after_confirming_access_on_even3(): void
    {
        $this->fakeEven3();

        $this->configure()->assertOk()
            ->assertJsonPath('data.external_event_id', '793572')
            ->assertJsonPath('data.external_event_title', 'Hackathon Teste')
            ->assertJsonPath('data.status', 'ACTIVE')
            ->assertJsonPath('data.api_version', 'V2')
            ->assertJsonPath('meta.warnings', []);

        Http::assertSent(fn ($r) => $r->url() === self::EVEN3_BASE.'/events/793572');
        $this->assertSame(1, AuditLog::query()->where('action', AuditAction::EVEN3_INTEGRATION_CONFIGURED)->count());

        // Repetir a mesma configuração não gera outro log.
        $this->configure()->assertOk();
        $this->assertSame(1, AuditLog::query()->where('action', AuditAction::EVEN3_INTEGRATION_CONFIGURED)->count());
    }

    public function test_differences_in_name_or_dates_are_only_warnings(): void
    {
        $this->fakeEven3();
        $this->event->forceFill(['name' => 'Outro nome', 'start_date' => '2026-11-01'])->save();

        $warnings = $this->configure()->assertOk()->json('meta.warnings');

        $this->assertCount(2, $warnings);
        $this->assertSame('ACTIVE', EventIntegration::query()->sole()->status->value);
    }

    public function test_event_not_accessible_by_the_key_is_refused(): void
    {
        Http::fake([self::EVEN3_BASE.'/*' => Http::response(['success' => false, 'message' => 'Resource not found', 'code' => 'NotFound'], 404)]);

        $this->configure(['external_event_id' => '1', 'status' => 'ACTIVE'])->assertUnprocessable()->assertJsonValidationErrors('external_event_id');
        $this->assertSame(0, EventIntegration::query()->count());
    }

    public function test_same_external_event_cannot_feed_two_events_and_event_has_one_even3(): void
    {
        $this->fakeEven3();
        $this->configure()->assertOk();

        $other = Event::factory()->create();
        $this->configure(event: $other)->assertUnprocessable()->assertJsonValidationErrors('external_event_id');

        // Banco: unique(provider, external_event_id) e unique(event_id, provider).
        foreach ([
            ['event_id' => $other->id, 'external_event_id' => '793572'],
            ['event_id' => $this->event->id, 'external_event_id' => '999'],
        ] as $row) {
            try {
                DB::transaction(fn () => DB::table('event_integrations')->insert($row + ['provider' => 'EVEN3', 'api_version' => 'V2', 'status' => 'ACTIVE']));
                $this->fail('O banco deveria recusar.');
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_disabled_integration_flag_blocks_configuration_and_sync(): void
    {
        config(['services.even3.enabled' => false]);

        $this->configure()->assertUnprocessable()->assertJsonValidationErrors('integration');
        Http::assertNothingSent();
    }

    public function test_status_never_exposes_token_or_secret(): void
    {
        $this->fakeEven3();
        $this->configure()->assertOk();

        $response = $this->actingAs($this->adminUser)->getJson("/api/v1/events/{$this->event->id}/integrations/even3/status?check=1")
            ->assertOk()
            ->assertJsonPath('data.configured', true)
            ->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.token_configured', true)
            ->assertJsonPath('data.webhook_configured', true)
            ->assertJsonPath('data.connected', true)
            ->assertJsonPath('data.api_version', 'V2')
            ->assertJsonPath('data.external_event_id', '793572');

        foreach ([self::EVEN3_TOKEN, substr(self::EVEN3_TOKEN, 0, 8), substr(self::EVEN3_TOKEN, -6), self::EVEN3_WEBHOOK_SECRET] as $secret) {
            $this->assertStringNotContainsString($secret, $response->getContent());
        }
    }

    public function test_tickets_are_discovered_without_mapping_renamed_and_deactivated(): void
    {
        $this->fakeEven3();
        $this->configure()->assertOk();

        $this->refakeHttp([self::EVEN3_BASE.'/events/793572/tickets' => Http::sequence()
            ->push([$this->externalTicket(10, 'Participante', lots: 3), $this->externalTicket(20, 'Público')])
            ->push([$this->externalTicket(10, 'Aluno participante', lots: 3), $this->externalTicket(20, 'Público')])
            ->push([$this->externalTicket(10, 'Aluno participante', lots: 3)])
            ->push([$this->externalTicket(10, 'Aluno participante', lots: 3)])]);

        $run = $this->sync('TICKETS')->assertCreated()->json('data');
        $this->assertSame('SUCCESS', $run['status']);
        $this->assertSame(2, $run['counters']['tickets_created']);

        $participant = ExternalTicketType::query()->where('external_ticket_id', '10')->sole();
        $this->assertNull($participant->internal_category, 'Entrada nova nasce sem mapeamento.');
        $this->actingAs($this->adminUser)->patchJson("/api/v1/external-ticket-types/{$participant->id}/mapping", ['internal_category' => 'PARTICIPANT'])->assertOk();

        // Renomeada: mesmo registro, mapeamento preservado.
        $run = $this->sync('TICKETS')->json('data');
        $this->assertSame(1, $run['counters']['tickets_updated']);
        $participant->refresh();
        $this->assertSame('Aluno participante', $participant->external_name);
        $this->assertSame(TicketCategory::Participant, $participant->internal_category);

        // Sumiu de uma leitura completa: inativa, nunca apagada.
        $run = $this->sync('TICKETS')->json('data');
        $this->assertSame(1, $run['counters']['tickets_deactivated']);
        $this->assertFalse(ExternalTicketType::query()->where('external_ticket_id', '20')->sole()->active);

        // Repetido sem mudança: sem duplicidade, sem update.
        $run = $this->sync('TICKETS')->json('data');
        $this->assertSame(['tickets_created' => 0, 'tickets_updated' => 0, 'tickets_unchanged' => 1, 'tickets_deactivated' => 0], array_intersect_key($run['counters'], array_flip(['tickets_created', 'tickets_updated', 'tickets_unchanged', 'tickets_deactivated'])));
        $this->assertSame(2, ExternalTicketType::query()->count());
    }

    public function test_tickets_without_id_or_empty_response_do_not_deactivate(): void
    {
        $this->fakeEven3();
        $this->configure()->assertOk();
        $integration = EventIntegration::query()->sole();
        $this->ticketType($integration, '10', 'Participante');

        $this->refakeHttp([self::EVEN3_BASE.'/events/793572/tickets' => Http::sequence()
            ->push([['title' => 'Sem lote', 'ticket_confirmed_quantity' => 0, 'prices' => []]])
            ->push([])]);

        $run = $this->sync('TICKETS')->json('data');
        $this->assertSame(1, $run['counters']['tickets_invalid']);
        $this->assertSame(['TICKET_WITHOUT_ID' => 1], $run['warning_codes']);

        $run = $this->sync('TICKETS')->json('data');
        $this->assertSame(['TICKETS_EMPTY_RESPONSE' => 1], $run['warning_codes']);
        $this->assertTrue(ExternalTicketType::query()->sole()->active);
    }

    public function test_mapping_set_changed_removed_and_audited_once_each(): void
    {
        $this->fakeEven3();
        $this->configure()->assertOk();
        $ticket = $this->ticketType(EventIntegration::query()->sole(), '10', 'Participante');
        $url = "/api/v1/external-ticket-types/{$ticket->id}/mapping";

        $this->actingAs($this->adminUser)->patchJson($url, ['internal_category' => 'PARTICIPANT'])->assertOk()->assertJsonPath('data.internal_category', 'PARTICIPANT');
        $this->actingAs($this->adminUser)->patchJson($url, ['internal_category' => 'PARTICIPANT'])->assertOk(); // sem mudança
        $this->actingAs($this->adminUser)->patchJson($url, ['internal_category' => 'PUBLIC'])->assertOk();
        $this->actingAs($this->adminUser)->patchJson($url, ['internal_category' => null])->assertOk()->assertJsonPath('data.mapped', false);
        $this->actingAs($this->adminUser)->patchJson($url, ['internal_category' => 'VALIDATOR'])->assertUnprocessable();
        $this->actingAs($this->adminUser)->patchJson($url, [])->assertUnprocessable();

        $logs = AuditLog::query()->where('action', AuditAction::EVEN3_TICKET_MAPPING_CHANGED)->orderBy('id')->get();
        $this->assertSame([null, 'PARTICIPANT', 'PUBLIC'], $logs->pluck('before_data.internal_category')->all());
        $this->assertSame(['PARTICIPANT', 'PUBLIC', null], $logs->pluck('after_data.internal_category')->all());
        $this->assertSame($this->event->id, $logs->first()->event_id);
    }

    public function test_authorization_matrix(): void
    {
        $this->fakeEven3();
        $this->configure()->assertOk();
        $ticket = $this->ticketType(EventIntegration::query()->sole(), '10', 'Participante');
        $consultant = $this->userWithRole(RoleCode::Consultant);
        $base = "/api/v1/events/{$this->event->id}";

        // Consultor consulta, mas não configura, mapeia nem sincroniza.
        $this->actingAs($consultant)->getJson("{$base}/integrations/even3/status")->assertOk();
        $this->actingAs($consultant)->getJson("{$base}/integrations/even3/tickets")->assertOk();
        $this->actingAs($consultant)->getJson("{$base}/integrations/even3/sync-runs")->assertOk();
        $this->actingAs($consultant)->getJson("{$base}/event-registrations")->assertOk();
        $this->actingAs($consultant)->getJson("{$base}/attendance")->assertOk();
        $this->configure(user: $consultant)->assertForbidden();
        $this->actingAs($consultant)->patchJson("/api/v1/external-ticket-types/{$ticket->id}/mapping", ['internal_category' => 'PUBLIC'])->assertForbidden();
        $this->actingAs($consultant)->postJson("{$base}/integrations/even3/sync", ['scope' => 'ALL'])->assertForbidden();
        $this->actingAs($consultant)->postJson("{$base}/event-registrations/materialize-participants")->assertForbidden();

        $sector = Sector::factory()->for($this->event)->create();
        foreach ([$this->sectorUser(RoleCode::Manager, $sector), $this->sectorUser(RoleCode::Editor, $sector), $this->userWithRole(RoleCode::Juror), $this->userWithRole(RoleCode::Voter)] as $user) {
            $this->actingAs($user)->getJson("{$base}/integrations/even3/status")->assertForbidden();
            $this->actingAs($user)->getJson("{$base}/event-registrations")->assertForbidden();
            $this->actingAs($user)->getJson("{$base}/attendance")->assertForbidden();
            $this->actingAs($user)->postJson("{$base}/integrations/even3/sync")->assertForbidden();
        }
    }
}
