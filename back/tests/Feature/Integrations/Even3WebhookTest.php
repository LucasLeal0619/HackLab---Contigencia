<?php

namespace Tests\Feature\Integrations;

use App\Domain\Integrations\Enums\RegistrationStatus;
use App\Domain\Integrations\Enums\TicketCategory;
use App\Domain\Integrations\Even3WebhookService;
use App\Domain\Integrations\RegistrationRecorder;
use App\Domain\Users\Enums\RoleCode;
use App\Models\AttendanceRecord;
use App\Models\AuditLog;
use App\Models\Event;
use App\Models\EventDay;
use App\Models\EventIntegration;
use App\Models\EventRegistration;
use App\Models\IntegrationWebhookReceipt;
use App\Models\Person;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\Concerns\Even3Fakes;
use Tests\DatabaseTestCase;

/**
 * POST /webhooks/even3: token de segurança no header configurável, idempotência por id do disparo,
 * gatilhos de inscrição/check-in, presença por dia (timezone do evento) e convergência com a API.
 */
class Even3WebhookTest extends DatabaseTestCase
{
    use Even3Fakes;

    private const CONFIRMED = 'Participante com inscrição confirmada no evento';

    private const PENDING = 'Participante com inscrição pendente no evento';

    private const CANCELLED = 'Participante com inscrição cancelada no evento';

    private const CHECKIN = 'Participante realizou check-in no evento';

    private const CHECKIN_ACTIVITY = 'Participante realizou check-in na atividade';

    private Event $event;

    private EventIntegration $integration;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureEven3();
        // Dias 04, 05 e 06/11/2026 (São Paulo).
        $this->event = Event::factory()->create(['timezone' => 'America/Sao_Paulo', 'start_date' => '2026-11-04', 'end_date' => '2026-11-06']);
        foreach ([1, 2, 3] as $n) {
            EventDay::factory()->for($this->event)->create(['day_number' => $n, 'date' => '2026-11-0'.($n + 3), 'label' => "Dia {$n}"]);
        }
        $this->integration = $this->even3Integration($this->event);
        $this->ticketType($this->integration, '10', 'Participante', TicketCategory::Participant);
    }

    public function test_correct_secret_processes_and_wrong_or_missing_secret_is_rejected(): void
    {
        $payload = $this->webhookPayload('d-1', self::CONFIRMED);

        $this->postWebhook($payload, secret: 'errado')->assertUnauthorized();
        $this->postWebhook($payload, secret: null)->assertUnauthorized();
        $this->postWebhook($payload, secret: self::EVEN3_TOKEN)->assertUnauthorized(); // token da API não serve
        $this->assertSame(0, IntegrationWebhookReceipt::query()->count());

        $this->postWebhook($payload)->assertOk()->assertJsonPath('data.status', 'PROCESSED');
        $this->assertSame(RegistrationStatus::Confirmed, EventRegistration::query()->sole()->status);
    }

    public function test_webhook_not_configured_or_secret_equal_to_api_token_is_unavailable(): void
    {
        config(['services.even3.webhook_header' => null]);
        $this->postWebhook($this->webhookPayload('d-1', self::CONFIRMED))->assertStatus(503);

        config(['services.even3.webhook_header' => self::EVEN3_WEBHOOK_HEADER, 'services.even3.webhook_secret' => self::EVEN3_TOKEN]);
        $this->postWebhook($this->webhookPayload('d-1', self::CONFIRMED), secret: self::EVEN3_TOKEN)->assertStatus(503);

        $this->assertSame(0, IntegrationWebhookReceipt::query()->count());
    }

    public function test_payload_without_delivery_id_is_a_bad_request(): void
    {
        $payload = $this->webhookPayload('x', self::CONFIRMED);
        unset($payload['id']);

        $this->postWebhook($payload)->assertStatus(400);
    }

    public function test_other_event_is_ignored_and_never_processed(): void
    {
        $this->postWebhook($this->webhookPayload('d-1', self::CONFIRMED, eventId: '999'))
            ->assertOk()->assertJsonPath('data.status', 'IGNORED')->assertJsonPath('data.error_code', 'EVENT_MISMATCH');

        $this->assertSame(0, EventRegistration::query()->count());
        $this->assertNull(IntegrationWebhookReceipt::query()->sole()->event_integration_id);
        // Reenvio do mesmo disparo órfão não duplica o recibo.
        $this->postWebhook($this->webhookPayload('d-1', self::CONFIRMED, eventId: '999'))->assertOk();
        $this->assertSame(1, IntegrationWebhookReceipt::query()->count());
    }

    public function test_duplicate_delivery_has_no_new_effect(): void
    {
        $payload = $this->webhookPayload('d-1', self::CHECKIN);

        $this->postWebhook($payload)->assertOk()->assertJsonPath('data.status', 'PROCESSED');
        $this->postWebhook($payload)->assertOk()->assertJsonPath('data.status', 'PROCESSED');

        $this->assertSame(1, IntegrationWebhookReceipt::query()->count());
        $this->assertSame(1, AttendanceRecord::query()->count());
        $this->assertSame(1, EventRegistration::query()->count());
        $this->assertSame(1, Person::query()->where('email', 'fulano@teste.even3')->count());
        $this->assertNull(IntegrationWebhookReceipt::query()->sole()->getAttribute('payload'), 'payload completo não é guardado');
        $this->assertSame(64, strlen(IntegrationWebhookReceipt::query()->sole()->payload_hash));
    }

    public function test_failed_delivery_can_be_reprocessed_on_resend(): void
    {
        $service = Mockery::mock(RegistrationRecorder::class);
        $service->shouldReceive('record')->once()->andThrow(new \RuntimeException('banco indisponível'));
        $this->app->instance(RegistrationRecorder::class, $service);

        $payload = $this->webhookPayload('d-1', self::CONFIRMED);
        $this->postWebhook($payload)->assertStatus(500)->assertJsonPath('data.status', 'FAILED');
        $this->assertSame('PROCESSING_FAILED', IntegrationWebhookReceipt::query()->sole()->error_code);

        $this->app->forgetInstance(RegistrationRecorder::class);
        $this->app->forgetInstance(Even3WebhookService::class);

        $this->postWebhook($payload)->assertOk()->assertJsonPath('data.status', 'PROCESSED');
        $this->assertSame(1, IntegrationWebhookReceipt::query()->count());
        $this->assertSame(1, EventRegistration::query()->count());
    }

    public function test_pending_confirmed_and_cancelled_triggers(): void
    {
        $this->postWebhook($this->webhookPayload('d-1', self::PENDING, created: 1793800000))->assertOk();
        $registration = EventRegistration::query()->sole();
        $this->assertSame(RegistrationStatus::Pending, $registration->status);
        $this->assertSame('111111', $registration->external_person_id);
        $this->assertSame('12345', $registration->external_registration_id);
        $this->assertSame('10', $registration->ticketType->external_ticket_id);

        $this->postWebhook($this->webhookPayload('d-2', self::CONFIRMED, created: 1793800100))->assertOk();
        $this->assertSame(RegistrationStatus::Confirmed, $registration->fresh()->status);

        // Gatilho "pendente" atrasado (mais antigo que a última mudança) não rebaixa o status.
        $this->postWebhook($this->webhookPayload('d-3', self::PENDING, created: 1793800050))->assertOk();
        $this->assertSame(RegistrationStatus::Confirmed, $registration->fresh()->status);

        $this->postWebhook($this->webhookPayload('d-4', self::CANCELLED, created: 1793800200))->assertOk();
        $this->assertSame(RegistrationStatus::Cancelled, $registration->fresh()->status);
    }

    public function test_dispatch_is_by_official_name_and_type_id_is_only_persisted(): void
    {
        // Nome oficial com qualquer id: despachado pelo nome; o id recebido fica no recibo.
        $this->postWebhook($this->webhookPayload('d-1', self::CANCELLED, typeId: 7))->assertOk()->assertJsonPath('data.status', 'PROCESSED');
        $this->assertSame(RegistrationStatus::Cancelled, EventRegistration::query()->sole()->status);
        $this->assertSame(7, IntegrationWebhookReceipt::query()->where('external_delivery_id', 'd-1')->sole()->event_type_id);

        // Id 2 com nome desconhecido não é tratado como "confirmada" (ids não são inventados nem usados para decidir).
        $this->postWebhook($this->webhookPayload('d-2', 'Nome alterado pela plataforma', typeId: 2))->assertOk()->assertJsonPath('data.status', 'IGNORED');
        $this->assertSame(RegistrationStatus::Cancelled, EventRegistration::query()->sole()->status);
        $this->assertSame(2, IntegrationWebhookReceipt::query()->where('external_delivery_id', 'd-2')->sole()->event_type_id);
    }

    public function test_unknown_or_out_of_scope_triggers_are_ignored_with_2xx(): void
    {
        foreach (['Participante com inscrição confirmada em uma atividade', 'Venda confirmada', 'Submissão recebida', 'Gatilho novo qualquer'] as $i => $name) {
            $this->postWebhook($this->webhookPayload("d-{$i}", $name))->assertOk()->assertJsonPath('data.status', 'IGNORED');
        }

        $this->assertSame(0, EventRegistration::query()->count());
        $this->assertSame(4, IntegrationWebhookReceipt::query()->where('status', 'IGNORED')->count());
    }

    public function test_check_ins_create_attendance_per_delivery_with_event_day_from_timezone(): void
    {
        // 2026-11-05T12:00Z = 09:00 em São Paulo → Dia 2. 2026-11-06T02:30Z = 23:30 do dia 05 em São Paulo → Dia 2 também.
        $this->postWebhook($this->webhookPayload('d-1', self::CHECKIN, created: 1793880000))->assertOk();
        $this->postWebhook($this->webhookPayload('d-2', self::CHECKIN, created: 1793932200))->assertOk();
        $this->postWebhook(array_replace($this->webhookPayload('d-3', self::CHECKIN_ACTIVITY, created: 1793880000), ['atividade' => ['id' => 77]]))->assertOk();

        $records = AttendanceRecord::query()->orderBy('id')->get();
        $this->assertCount(3, $records, 'vários check-ins no mesmo dia são permitidos');
        $day2 = EventDay::query()->where('day_number', 2)->value('id');
        $this->assertSame([$day2, $day2, $day2], $records->pluck('event_day_id')->all());
        $this->assertSame(['EVENT', 'EVENT', 'ACTIVITY'], $records->map(fn ($r) => $r->kind->value)->all());
        $this->assertSame('77', $records[2]->external_activity_id);
        $this->assertSame([null, null, null], $records->pluck('checked_in_at')->all(), 'horário exato do check-in não documentado: fica nulo');
        $this->assertSame('2026-11-05T12:00:00+00:00', $records[0]->observed_at->toIso8601String());

        // Presença diária derivada: um dia presente, mesmo com 3 check-ins.
        $consultant = $this->userWithRole(RoleCode::Consultant);
        $this->actingAs($consultant)->getJson("/api/v1/events/{$this->event->id}/attendance")
            ->assertOk()
            ->assertJsonPath('data.0.check_ins', 3)
            ->assertJsonCount(1, 'data.0.days_present')
            ->assertJsonPath('data.0.days_present.0.date', '2026-11-05');
        $this->actingAs($consultant)->getJson("/api/v1/events/{$this->event->id}/attendance?event_day_id=".EventDay::query()->where('day_number', 1)->value('id'))
            ->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_check_in_outside_event_days_or_without_timezone_has_no_day_and_a_warning(): void
    {
        $this->postWebhook($this->webhookPayload('d-1', self::CHECKIN, created: 1795000000))->assertOk() // 2026-11-18
            ->assertJsonPath('data.error_code', 'EVENT_DAY_NOT_FOUND');

        $this->event->forceFill(['timezone' => null])->save();
        $this->postWebhook($this->webhookPayload('d-2', self::CHECKIN))->assertOk()->assertJsonPath('data.error_code', 'EVENT_TIMEZONE_MISSING');

        $this->assertSame([null, null], AttendanceRecord::query()->orderBy('id')->pluck('event_day_id')->all());
        $this->assertSame(3, EventDay::query()->count(), 'nunca cria EventDay');
    }

    public function test_webhook_first_then_api_sync_converge_to_one_registration(): void
    {
        $this->postWebhook($this->webhookPayload('d-1', self::CONFIRMED, person: ['email' => 'Maria@Teste.Even3', 'cpf' => '123.456.789-09', 'nome' => 'Maria']))->assertOk();

        $this->refakeHttp([self::EVEN3_BASE.'/attendees*' => Http::response($this->page([
            $this->externalAttendee(900, ['name' => 'Maria', 'email' => 'maria@teste.even3', 'document_number' => '12345678909']),
        ]))]);
        $admin = $this->admin();
        $this->actingAs($admin)->postJson("/api/v1/events/{$this->event->id}/integrations/even3/sync", ['scope' => 'ATTENDEES'])->assertCreated()
            ->assertJsonPath('data.counters.attendees_updated', 1)->assertJsonPath('data.counters.persons_created', 0);

        $registration = EventRegistration::query()->sole();
        $this->assertSame(['900', '12345', '111111'], [$registration->external_attendee_id, $registration->external_registration_id, $registration->external_person_id]);
        $this->assertSame(1, Person::query()->where('email', 'maria@teste.even3')->count());
    }

    public function test_identity_conflict_in_webhook_is_failed_but_acknowledged(): void
    {
        Person::factory()->create(['email' => 'a@teste.even3', 'document' => null]);
        Person::factory()->create(['email' => null, 'document' => '11111111111']);

        $this->postWebhook($this->webhookPayload('d-1', self::CONFIRMED, person: ['email' => 'a@teste.even3', 'cpf' => '111.111.111-11']))
            ->assertOk()->assertJsonPath('data.status', 'FAILED')->assertJsonPath('data.error_code', 'IDENTITY_CONFLICT');

        $this->assertSame(0, EventRegistration::query()->count());
    }

    public function test_webhooks_never_create_audit_logs_users_or_store_secrets(): void
    {
        $this->postWebhook($this->webhookPayload('d-1', self::CONFIRMED))->assertOk();
        $this->postWebhook($this->webhookPayload('d-2', self::CHECKIN))->assertOk();

        $this->assertSame(0, AuditLog::query()->count());
        $this->assertSame(0, User::query()->count());
        $dump = json_encode(IntegrationWebhookReceipt::query()->get()->toArray());
        $this->assertStringNotContainsString(self::EVEN3_WEBHOOK_SECRET, $dump);
        $this->assertStringNotContainsString('fulano@teste.even3', $dump, 'recibo não guarda dados pessoais do payload');
    }
}
