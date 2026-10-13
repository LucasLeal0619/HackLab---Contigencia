<?php

namespace Tests\Feature\Reports;

use App\Domain\Challenges\Enums\ChallengeStatus;
use App\Domain\Integrations\Enums\RegistrationStatus;
use App\Domain\Occurrences\Enums\OccurrenceStatus;
use App\Domain\Participants\Enums\ParticipantStatus;
use App\Domain\Tasks\Enums\TaskStatus;
use App\Models\Challenge;
use App\Models\Company;
use App\Models\Event;
use App\Models\Occurrence;
use App\Models\Participant;
use App\Models\Person;
use App\Models\SchoolClass;
use App\Models\Sector;
use App\Models\Task;
use App\Models\Team;
use Tests\Concerns\JuryScenario;
use Tests\Concerns\ReportScenario;
use Tests\DatabaseTestCase;

/**
 * Relatório Geral: contagens reais de um cenário conhecido (e nada de outro evento).
 */
class GeneralReportTest extends DatabaseTestCase
{
    use JuryScenario;
    use ReportScenario;

    public function test_counts_match_a_known_scenario(): void
    {
        $this->buildJuryScenario(); // evento + 3 equipes ativas + jurados
        $event = $this->event;
        $day1 = $this->day($event, 1, '2026-11-11');
        $day2 = $this->day($event, 2, '2026-11-12');

        $class = SchoolClass::factory()->for($event)->create();
        SchoolClass::factory()->for($event)->create(['active' => false]);
        $p1 = Participant::factory()->for($event)->create(['class_id' => $class->id, 'status' => ParticipantStatus::Available]);
        $p2 = Participant::factory()->for($event)->create(['status' => ParticipantStatus::Available]);
        Participant::factory()->for($event)->create(['status' => ParticipantStatus::Withdrawn]);
        $this->joinTeam($p1, $this->team1);

        $company = Company::factory()->for($event)->create();
        $challenge = Challenge::factory()->for($event)->for($company)->create(['status' => ChallengeStatus::Approved]);
        $this->team1->forceFill(['challenge_id' => $challenge->id])->save();
        Challenge::factory()->for($event)->for($company)->create(['status' => ChallengeStatus::Draft]);

        $sector = Sector::factory()->for($event)->create();
        Sector::factory()->for($event)->create(['active' => false]);
        Task::factory()->between($sector)->create();
        Task::factory()->between($sector)->create(['due_at' => now()->subDay()]); // vencida
        Task::factory()->between($sector)->create(['status' => TaskStatus::Completed, 'resolved_at' => now(), 'due_at' => now()->subDay()]); // fechada: não vence
        Occurrence::factory()->between($sector)->create();
        Occurrence::factory()->between($sector)->create(['status' => OccurrenceStatus::Resolved, 'resolution' => 'Resolvida.', 'resolved_at' => now()]);

        $integration = $this->even3Integration($event);
        $r1 = $this->registration($integration, $p1->person);
        $this->registration($integration, $p2->person, RegistrationStatus::Pending);
        $r3 = $this->registration($integration, Person::factory()->create()); // público, sem participante
        $this->checkIn($r1, $day1, 2);
        $this->checkIn($r3, $day1);
        $this->checkIn($r3, $day2);

        $this->assign($this->jurorA, [$this->team1, $this->team2]);

        // Ruído de outro evento: nada disso pode contar.
        $other = Event::factory()->create();
        Participant::factory()->for($other)->count(4)->create();
        Team::factory()->for($other)->create();
        Task::factory()->between(Sector::factory()->for($other)->create())->create(['due_at' => now()->subDay()]);

        $summary = $this->report($this->admin(), 'general', $event)->assertOk()->json('data.summary');

        $this->assertSame(['total' => 3, 'by_status' => ['AVAILABLE' => 2, 'UNAVAILABLE' => 0, 'WITHDRAWN' => 1], 'with_team' => 1, 'without_team' => 2], $summary['participants']);
        $this->assertSame(['total' => 2, 'active' => 1], $summary['classes']);
        $this->assertSame(3, $summary['teams']['total']);
        $this->assertSame(1, $summary['companies']['total']);
        $this->assertSame(2, $summary['challenges']['total']);
        $this->assertSame(1, $summary['challenges']['with_team']);
        $this->assertSame(['total' => 2, 'active' => 1], $summary['sectors']);
        $this->assertSame(['total' => 3, 'by_status' => ['PENDING' => 2, 'IN_PROGRESS' => 0, 'COMPLETED' => 1], 'open' => 2, 'overdue' => 1], $summary['tasks']);
        $this->assertSame(2, $summary['occurrences']['total']);
        $this->assertSame(1, $summary['occurrences']['open']);
        $this->assertSame(3, $summary['registrations']['total']);
        $this->assertSame(2, $summary['registrations']['by_status']['CONFIRMED']);
        $this->assertSame(1, $summary['registrations']['by_status']['PENDING']);
        $this->assertSame(2, $summary['attendance']['people_with_presence']);
        $this->assertSame(4, $summary['attendance']['check_ins']);
        $this->assertSame([
            ['event_day_id' => $day1->id, 'people_present' => 2, 'check_ins' => 3],
            ['event_day_id' => $day2->id, 'people_present' => 1, 'check_ins' => 1],
        ], $summary['attendance']['by_day']);
        // completion_percent 0.0 chega como 0 no JSON.
        $this->assertEquals(['expected' => 2, 'submitted' => 0, 'pending' => 2, 'completion_percent' => 0], $summary['evaluations']);
        $this->assertSame(0, $summary['voting']['sessions']);
        $this->assertSame(['published' => false, 'version' => null, 'published_at' => null], $summary['official_result']);
    }

    public function test_event_without_integration_works_with_zeros(): void
    {
        $event = Event::factory()->create();

        $summary = $this->report($this->admin(), 'general', $event)->assertOk()->json('data.summary');

        $this->assertSame(0, $summary['registrations']['total']);
        $this->assertSame(0, $summary['attendance']['people_with_presence']);
        $this->assertSame([], $summary['attendance']['by_day']);
        $this->assertNull($summary['evaluations']['completion_percent']);
    }

    public function test_event_days_are_real_and_ordered(): void
    {
        $event = Event::factory()->create();
        $this->day($event, 2, '2026-11-12');
        $this->day($event, 1, '2026-11-11');

        $days = $this->report($this->admin(), 'general', $event)->json('data.event.days');

        $this->assertSame(['2026-11-11', '2026-11-12'], array_column($days, 'date'));
        $this->assertSame([1, 2], array_column($days, 'day_number'));
    }
}
