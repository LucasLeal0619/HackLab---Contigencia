<?php

namespace Tests\Feature\Reports;

use App\Domain\Participants\Enums\ParticipantStatus;
use App\Domain\Reports\ParticipantsPresenceReportBuilder;
use App\Models\Event;
use App\Models\Participant;
use App\Models\Person;
use App\Models\SchoolClass;
use App\Models\Team;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\ReportScenario;
use Tests\DatabaseTestCase;

/**
 * Participantes e Presença: presença por Person e dia (PRESENT / NO_RECORD), nunca "ausente" inventado.
 */
class ParticipantsPresenceReportTest extends DatabaseTestCase
{
    use ReportScenario;

    public function test_presence_by_person_and_day(): void
    {
        $event = Event::factory()->create();
        $day1 = $this->day($event, 1, '2026-11-11');
        $day2 = $this->day($event, 2, '2026-11-12');
        $class = SchoolClass::factory()->for($event)->create(['name' => 'Turma A']);
        $team = Team::factory()->for($event)->create(['name' => 'Equipe Alfa']);
        $integration = $this->even3Integration($event);

        $make = fn (string $name, array $attributes = []) => Participant::factory()->for($event)->create(['person_id' => Person::factory()->create(['full_name' => $name])->id] + $attributes);

        $ana = $make('Ana', ['class_id' => $class->id]);          // inscrição + check-in nos dois dias
        $bia = $make('Bia');                                      // inscrição sem check-in
        $make('Caio', ['status' => ParticipantStatus::Unavailable]); // sem inscrição externa
        $duda = $make('Duda');                                    // duas inscrições: check-in só pela segunda
        $edu = $make('Edu');                                      // dois check-ins no mesmo dia
        $this->joinTeam($ana, $team);

        $this->checkIn($this->registration($integration, $ana->person), $day1);
        $anaSecond = $this->registration($integration, $ana->person);
        $this->checkIn($anaSecond, $day2);
        $this->registration($integration, $bia->person);
        $this->registration($integration, $duda->person);
        $this->checkIn($this->registration($integration, $duda->person), $day2);
        $this->checkIn($this->registration($integration, $edu->person), $day1, 2);
        $this->checkIn($this->registration($integration, $edu->person), null); // check-in sem dia não conta para dia nenhum

        // Mesma Person com check-in em outro evento: não conta aqui.
        $other = Event::factory()->create();
        $otherDay = $this->day($other, 1, '2026-11-11');
        $this->checkIn($this->registration($this->even3Integration($other, 'outro-evento'), $bia->person), $otherDay);

        $data = $this->report($this->admin(), 'participants-presence', $event)->assertOk()->json('data');
        $rows = collect($data['participants'])->keyBy('name');
        $presence = fn (string $name) => $rows[$name]['presence'];

        $this->assertSame(['Ana', 'Bia', 'Caio', 'Duda', 'Edu'], $rows->keys()->all());
        $this->assertSame([(string) $day1->id => 'PRESENT', (string) $day2->id => 'PRESENT'], $presence('Ana'));
        $this->assertSame([(string) $day1->id => 'NO_RECORD', (string) $day2->id => 'NO_RECORD'], $presence('Bia'));
        $this->assertSame([(string) $day1->id => 'NO_RECORD', (string) $day2->id => 'NO_RECORD'], $presence('Caio'));
        $this->assertSame([(string) $day1->id => 'NO_RECORD', (string) $day2->id => 'PRESENT'], $presence('Duda'));
        $this->assertSame([(string) $day1->id => 'PRESENT', (string) $day2->id => 'NO_RECORD'], $presence('Edu'));

        $this->assertSame(['id' => $class->id, 'name' => 'Turma A'], $rows['Ana']['class']);
        $this->assertSame(['id' => $team->id, 'name' => 'Equipe Alfa'], $rows['Ana']['team']);
        $this->assertNull($rows['Bia']['team']);
        $this->assertSame('UNAVAILABLE', $rows['Caio']['status']);

        $summary = $data['summary'];
        $this->assertSame(5, $summary['total']);
        $this->assertSame(['AVAILABLE' => 4, 'UNAVAILABLE' => 1, 'WITHDRAWN' => 0], $summary['by_status']);
        $this->assertSame(1, $summary['with_team']);
        $this->assertSame(4, $summary['without_team']);
        $this->assertSame([
            ['event_day_id' => $day1->id, 'participants_present' => 2],
            ['event_day_id' => $day2->id, 'participants_present' => 2],
        ], $summary['presence_by_day']);
        $this->assertSame(3, $summary['with_any_presence']);
        $this->assertSame([['class' => ['id' => $class->id, 'name' => 'Turma A'], 'total' => 1], ['class' => null, 'total' => 4]], $summary['by_class']);

        // Nunca "ausente" e nenhum dado de contato/documento.
        $this->assertStringNotContainsString('ABSENT', json_encode($data));
        $this->assertPayloadExcludes($data, ['email', 'phone', 'document', 'source_document', 'check_in_code', 'password']);
    }

    public function test_no_report_leaks_sensitive_registration_or_person_data(): void
    {
        $event = Event::factory()->create();
        $day = $this->day($event, 1, '2026-11-11');
        $person = Person::factory()->create(['document' => '11122233344', 'email' => 'pessoa.sigilosa@teste.local', 'phone' => '+55 11 90000-0000']);
        Participant::factory()->for($event)->create(['person_id' => $person->id]);
        $registration = $this->registration($this->even3Integration($event), $person, attributes: [
            'check_in_code' => 'CHK-SIGILO-777', 'source_document' => '98765432100', 'source_email' => 'origem.sigilosa@teste.local',
        ]);
        $this->checkIn($registration, $day);

        foreach (ReportAuthorizationTest::TYPES as $type) {
            $data = $this->report($this->admin(), $type, $event)->assertOk()->json('data');
            $this->assertPayloadExcludes(
                $data,
                ['password', 'document', 'source_document', 'check_in_code', 'api_token', 'webhook_secret', 'email', 'phone'],
                ['11122233344', '98765432100', 'CHK-SIGILO-777', 'pessoa.sigilosa@teste.local', 'origem.sigilosa@teste.local', '90000-0000'],
            );
        }
    }

    public function test_presence_query_count_does_not_grow_with_participants(): void
    {
        $event = Event::factory()->create();
        $this->day($event, 1, '2026-11-11');
        $this->day($event, 2, '2026-11-12');
        $admin = $this->admin();

        // Mede só o builder (o ciclo HTTP/autenticação não interessa aqui).
        $builder = app(ParticipantsPresenceReportBuilder::class);
        $count = function () use ($admin, $event, $builder) {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $builder->build($event->fresh(), $admin->fresh());
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $queries;
        };

        Participant::factory()->for($event)->count(3)->create();
        $few = $count();
        Participant::factory()->for($event)->count(30)->create();
        $many = $count();

        $this->assertSame($few, $many, 'presença resolvida em lote (sem consulta por participante/dia)');
    }
}
