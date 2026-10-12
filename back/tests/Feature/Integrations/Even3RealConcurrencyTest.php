<?php

namespace Tests\Feature\Integrations;

use App\Domain\Integrations\Enums\RegistrationStatus;
use App\Domain\Integrations\Enums\TicketCategory;
use App\Models\AttendanceRecord;
use App\Models\Event;
use App\Models\EventIntegration;
use App\Models\EventRegistration;
use App\Models\IntegrationSyncRun;
use App\Models\IntegrationWebhookReceipt;
use App\Models\Participant;
use App\Models\Person;
use App\Models\User;
use Tests\Concerns\Even3Fakes;
use Tests\RealConcurrencyTestCase;

/**
 * Concorrência real (processos PHP paralelos) na integração Even3: syncs simultâneos, webhooks iguais,
 * webhook + sync do mesmo inscrito e materializações simultâneas. Resultado: 1 Person, 1 inscrição,
 * no máximo 1 Participant por pessoa e nenhum check-in duplicado para o mesmo disparo.
 */
class Even3RealConcurrencyTest extends RealConcurrencyTestCase
{
    use Even3Fakes;

    private Event $event;

    private EventIntegration $integration;

    private User $adminUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->event = Event::factory()->create(['timezone' => 'America/Sao_Paulo']);
        $this->integration = $this->even3Integration($this->event);
        $this->ticketType($this->integration, '10', 'Participante', TicketCategory::Participant);
        $this->adminUser = $this->admin();
    }

    /**
     * @return array<string, mixed>
     */
    private function even3Args(array $extra = []): array
    {
        return $extra + [
            'actor_id' => $this->adminUser->id,
            'external_event_id' => self::EXTERNAL_EVENT_ID,
            'even3' => [
                'enabled' => true, 'base_url' => self::EVEN3_BASE, 'token' => self::EVEN3_TOKEN, 'timeout' => 15, 'connect_timeout' => 5,
                'retries' => 0, 'retry_backoff_ms' => 0, 'page_size' => 100, 'max_pages' => 10,
                'webhook_header' => self::EVEN3_WEBHOOK_HEADER, 'webhook_secret' => self::EVEN3_WEBHOOK_SECRET,
            ],
        ];
    }

    private function persons(): int
    {
        return Person::query()->where('id', '<>', $this->adminUser->person_id)->count();
    }

    public function test_simultaneous_syncs_never_duplicate(): void
    {
        $attendees = array_map(fn (int $n) => $this->externalAttendee($n), range(1, 6));
        $args = $this->even3Args(['integration_id' => $this->integration->id, 'attendees' => $attendees]);

        $results = $this->runConcurrently([['even3_sync', $args], ['even3_sync', $args], ['even3_sync', $args]]);

        $ran = $this->countWhere($results, fn ($r) => $r['conflict'] === false);
        $this->assertGreaterThanOrEqual(1, $ran);
        $this->assertSame($ran, IntegrationSyncRun::query()->count(), 'quem recebeu conflito não criou sync run');
        $this->assertSame(0, IntegrationSyncRun::query()->where('status', 'RUNNING')->count());
        $this->assertSame(6, $this->persons());
        $this->assertSame(6, EventRegistration::query()->count());
    }

    public function test_identical_simultaneous_webhooks_have_a_single_effect(): void
    {
        $payload = $this->webhookPayload('entrega-1', 'Participante realizou check-in no evento');
        $args = $this->even3Args(['payload' => $payload]);

        $results = $this->runConcurrently(array_fill(0, 4, ['even3_webhook', $args]));

        $this->assertSame([200, 200, 200, 200], array_column($results, 'http'));
        $this->assertSame(1, IntegrationWebhookReceipt::query()->count());
        $this->assertSame(1, AttendanceRecord::query()->count());
        $this->assertSame(1, EventRegistration::query()->count());
        $this->assertSame(1, $this->persons());
    }

    public function test_webhook_and_sync_of_the_same_attendee_converge(): void
    {
        $person = ['email' => 'maria@teste.even3', 'cpf' => '123.456.789-09', 'nome' => 'Maria'];
        $jobs = [];
        foreach (range(1, 3) as $n) {
            $jobs[] = ['even3_webhook', $this->even3Args(['payload' => $this->webhookPayload("entrega-{$n}", 'Participante com inscrição confirmada no evento', person: $person)])];
        }
        $jobs[] = ['even3_sync', $this->even3Args(['integration_id' => $this->integration->id, 'attendees' => [
            $this->externalAttendee(900, ['name' => 'Maria', 'email' => 'maria@teste.even3', 'document_number' => '12345678909']),
        ]])];

        $this->runConcurrently($jobs);

        $this->assertSame(1, $this->persons());
        $registration = EventRegistration::query()->sole();
        $this->assertSame('900', $registration->external_attendee_id);
        $this->assertSame('12345', $registration->external_registration_id);
        $this->assertSame(RegistrationStatus::Confirmed, $registration->status);
    }

    public function test_simultaneous_materializations_create_one_participant_per_person(): void
    {
        foreach (range(1, 4) as $n) {
            $person = Person::factory()->create();
            $registration = new EventRegistration([
                'event_id' => $this->event->id, 'event_integration_id' => $this->integration->id, 'person_id' => $person->id,
                'external_attendee_id' => (string) $n, 'external_ticket_type_id' => $this->integration->ticketTypes()->value('id'), 'source_name' => "Pessoa {$n}",
            ]);
            $registration->forceFill(['status' => RegistrationStatus::Confirmed])->save();
        }

        $results = $this->runConcurrently(array_fill(0, 3, ['materialize', ['event_id' => $this->event->id, 'actor_id' => $this->adminUser->id]]));

        $this->assertSame(4, array_sum(array_column($results, 'created')));
        $this->assertSame(4, Participant::query()->count());
        $this->assertSame(4, Participant::query()->distinct()->count('person_id'));
    }
}
