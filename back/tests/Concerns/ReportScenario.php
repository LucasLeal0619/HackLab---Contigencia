<?php

namespace Tests\Concerns;

use App\Domain\Integrations\Enums\AttendanceKind;
use App\Domain\Integrations\Enums\AttendanceSource;
use App\Domain\Integrations\Enums\RegistrationStatus;
use App\Models\AttendanceRecord;
use App\Models\Event;
use App\Models\EventDay;
use App\Models\EventIntegration;
use App\Models\EventRegistration;
use App\Models\Participant;
use App\Models\Person;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

/**
 * Apoio aos testes de relatórios: inscrições externas, check-ins, vínculo de equipe e varredura de privacidade.
 */
trait ReportScenario
{
    use Even3Fakes;

    protected function report(User $user, string $type, Event $event): TestResponse
    {
        return $this->actingAs($user)->getJson("/api/v1/events/{$event->id}/reports/{$type}");
    }

    protected function day(Event $event, int $number, string $date): EventDay
    {
        return EventDay::factory()->for($event)->create(['day_number' => $number, 'date' => $date, 'label' => "Dia {$number}"]);
    }

    protected function registration(EventIntegration $integration, Person $person, RegistrationStatus $status = RegistrationStatus::Confirmed, array $attributes = []): EventRegistration
    {
        static $sequence = 0;
        $sequence++;
        $registration = new EventRegistration(array_replace([
            'event_id' => $integration->event_id, 'event_integration_id' => $integration->id, 'person_id' => $person->id,
            'external_attendee_id' => "att-{$sequence}", 'source_name' => $person->full_name,
        ], $attributes));
        $registration->forceFill(['status' => $status, 'status_changed_at' => now()])->save();

        return $registration;
    }

    protected function checkIn(EventRegistration $registration, ?EventDay $day, int $times = 1): void
    {
        for ($i = 0; $i < $times; $i++) {
            AttendanceRecord::query()->create([
                'event_id' => $registration->event_id,
                'event_integration_id' => $registration->event_integration_id,
                'event_registration_id' => $registration->id,
                'event_day_id' => $day?->id,
                'kind' => AttendanceKind::Event,
                'observed_at' => now(),
                'source' => AttendanceSource::Api,
            ]);
        }
    }

    protected function joinTeam(Participant $participant, Team $team): void
    {
        DB::table('team_members')->insert([
            'event_id' => $team->event_id, 'team_id' => $team->id, 'participant_id' => $participant->id,
            'joined_at' => now(), 'active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /**
     * Varre o payload inteiro (chaves e valores) e falha se aparecer alguma chave ou valor proibido.
     *
     * @param  list<string>  $keys
     * @param  list<string>  $values
     */
    protected function assertPayloadExcludes(array $payload, array $keys, array $values = []): void
    {
        $walk = function ($node, string $path) use (&$walk, $keys, $values) {
            if (is_array($node)) {
                foreach ($node as $key => $child) {
                    if (is_string($key)) {
                        $this->assertNotContains($key, $keys, "Chave proibida '{$key}' em {$path}");
                    }
                    $walk($child, "{$path}.{$key}");
                }

                return;
            }
            if (is_string($node)) {
                foreach ($values as $value) {
                    $this->assertStringNotContainsString($value, $node, "Valor proibido em {$path}");
                }
            }
        };
        $walk($payload, 'data');
    }
}
