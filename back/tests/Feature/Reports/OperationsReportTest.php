<?php

namespace Tests\Feature\Reports;

use App\Domain\Challenges\Enums\ChallengeStatus;
use App\Domain\Demands\Enums\DemandPriority;
use App\Domain\Occurrences\Enums\OccurrenceCategory;
use App\Domain\Occurrences\Enums\OccurrenceStatus;
use App\Domain\Tasks\Enums\TaskStatus;
use App\Models\Challenge;
use App\Models\Company;
use App\Models\Event;
use App\Models\Meeting;
use App\Models\Occurrence;
use App\Models\Sector;
use App\Models\Task;
use App\Models\Team;
use Tests\Concerns\ReportScenario;
use Tests\DatabaseTestCase;

/**
 * Gestão e Operação: setores, reuniões, empresas/desafios/equipes, pendências e ocorrências — só do evento.
 */
class OperationsReportTest extends DatabaseTestCase
{
    use ReportScenario;

    public function test_operations_content(): void
    {
        $event = Event::factory()->create();
        $day = $this->day($event, 1, '2026-11-11');
        $tech = Sector::factory()->for($event)->create(['name' => 'Tecnologia']);
        $prod = Sector::factory()->for($event)->create(['name' => 'Produção']);
        Sector::factory()->for($event)->create(['name' => 'Antigo', 'active' => false]);
        Meeting::factory()->for($event)->create(['title' => 'Alinhamento geral', 'sector_id' => null]);
        Meeting::factory()->for($event)->create(['title' => 'Reunião da Tecnologia', 'sector_id' => $tech->id]);

        $company = Company::factory()->for($event)->create(['name' => 'Empresa X']);
        $challenge = Challenge::factory()->for($event)->for($company)->create(['title' => 'Desafio X', 'status' => ChallengeStatus::Distributed]);
        $team = Team::factory()->for($event)->create(['name' => 'Equipe X', 'challenge_id' => $challenge->id]);

        $open = Task::factory()->between($tech, $prod)->create(['priority' => DemandPriority::High]);
        $late = Task::factory()->between($tech)->create(['due_at' => now()->subHour()]);
        Task::factory()->between($prod)->create(['status' => TaskStatus::Completed, 'resolved_at' => now()]);
        Occurrence::factory()->between($tech)->create(['category' => OccurrenceCategory::Technology, 'priority' => DemandPriority::Urgent, 'event_day_id' => $day->id, 'team_id' => $team->id, 'occurred_at' => now()]);
        Occurrence::factory()->between($prod)->create(['status' => OccurrenceStatus::Resolved, 'resolution' => 'Cabo trocado.', 'resolved_at' => now()]);

        // Outro evento: não pode aparecer.
        $other = Event::factory()->create();
        $otherSector = Sector::factory()->for($other)->create(['name' => 'Setor Alheio']);
        Task::factory()->between($otherSector)->create(['title' => 'Pendência alheia']);
        Company::factory()->for($other)->create(['name' => 'Empresa Alheia']);

        $data = $this->report($this->admin(), 'operations', $event)->assertOk()->json('data');

        $this->assertSame(['total' => 3, 'active' => 2, 'inactive' => 1], $data['sectors']['summary']);
        $this->assertSame(2, $data['meetings']['summary']['total']);
        $this->assertSame(1, $data['meetings']['summary']['general']);
        $this->assertSame(1, $data['meetings']['summary']['sector']);
        $meeting = collect($data['meetings']['items'])->firstWhere('title', 'Reunião da Tecnologia');
        $this->assertSame(['SECTOR', 'Tecnologia'], [$meeting['scope'], $meeting['sector']['name']]);

        $this->assertSame([['id' => $company->id, 'name' => 'Empresa X', 'type' => $company->type?->value, 'status' => $company->status?->value, 'challenges_count' => 1]], $data['companies']['items']);
        $this->assertSame(['id' => $team->id, 'name' => 'Equipe X'], $data['challenges']['items'][0]['team']);
        $this->assertSame(1, $data['teams']['summary']['with_challenge']);

        $tasks = $data['tasks'];
        $this->assertSame(3, $tasks['summary']['total']);
        $this->assertSame(2, $tasks['summary']['open']);
        $this->assertSame(1, $tasks['summary']['overdue']);
        $this->assertSame(['PENDING' => 2, 'IN_PROGRESS' => 0, 'COMPLETED' => 1], $tasks['summary']['by_status']);
        $this->assertSame(1, $tasks['summary']['by_priority']['HIGH']);
        $items = collect($tasks['items'])->keyBy('id');
        $this->assertTrue($items[$late->id]['overdue']);
        $this->assertFalse($items[$open->id]['overdue']);
        $this->assertSame(['Tecnologia', 'Produção'], [$items[$open->id]['origin_sector']['name'], $items[$open->id]['responsible_sector']['name']]);

        $occurrences = $data['occurrences'];
        $this->assertSame(['OPEN' => 1, 'IN_PROGRESS' => 0, 'RESOLVED' => 1], $occurrences['summary']['by_status']);
        $this->assertSame(1, $occurrences['summary']['by_category']['TECHNOLOGY']);
        $this->assertSame(1, $occurrences['summary']['by_priority']['URGENT']);
        $resolved = collect($occurrences['items'])->firstWhere('status', 'RESOLVED');
        $openOccurrence = collect($occurrences['items'])->firstWhere('status', 'OPEN');
        $this->assertSame('Cabo trocado.', $resolved['resolution']);
        $this->assertNull($openOccurrence['resolution']);
        $this->assertSame($day->id, $openOccurrence['event_day']['id']);
        $this->assertSame('Equipe X', $openOccurrence['team']['name']);

        $json = json_encode($data);
        $this->assertStringNotContainsString('Setor Alheio', $json);
        $this->assertStringNotContainsString('Pendência alheia', $json);
        $this->assertStringNotContainsString('Empresa Alheia', $json);
        // Empresa: sem documento, e-mail ou telefone no relatório.
        $this->assertPayloadExcludes($data, ['document', 'email', 'phone', 'legal_name', 'password']);
    }
}
