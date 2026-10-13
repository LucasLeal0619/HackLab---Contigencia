<?php

namespace Tests\Feature\Events;

use App\Domain\Demands\Enums\DemandPriority;
use App\Domain\Occurrences\Enums\OccurrenceStatus;
use App\Domain\Participants\Enums\ParticipantStatus;
use App\Domain\Tasks\Enums\TaskStatus;
use App\Domain\Users\Enums\PermissionCode;
use App\Domain\Users\Enums\RoleCode;
use App\Models\AuditLog;
use App\Models\Challenge;
use App\Models\Company;
use App\Models\Event;
use App\Models\Occurrence;
use App\Models\Participant;
use App\Models\Person;
use App\Models\Task;
use App\Models\Team;
use App\Models\TeamMember;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\DemandScenario;
use Tests\DatabaseTestCase;

/**
 * GET /events/{event}/dashboard: as contagens do Dashboard numa requisição só, com a mesma Policy e o mesmo
 * recorte setorial das listagens de destino. Bloco sem permissão vem null; nada de dados pessoais.
 */
class EventDashboardTest extends DatabaseTestCase
{
    use DemandScenario;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildDemandScenario();
        $this->seedEvent();
    }

    private function seedEvent(): void
    {
        $team = Team::factory()->for($this->event)->create();
        $inTeam = Participant::factory()->for($this->event)->create();
        TeamMember::query()->create(['event_id' => $this->event->id, 'team_id' => $team->id, 'participant_id' => $inTeam->id, 'joined_at' => now(), 'active' => true]);
        Participant::factory()->for($this->event)->create(['person_id' => Person::factory()->create(['full_name' => 'Pessoa Sigilosa', 'email' => 'sigilo@teste.local'])->id]);
        Participant::factory()->for($this->event)->create(['status' => ParticipantStatus::Withdrawn]);
        Company::factory()->count(2)->for($this->event)->create();
        Challenge::factory()->for($this->event)->create();

        Task::factory()->between($this->sectorA)->create();
        Task::factory()->between($this->sectorA)->create(['due_at' => now()->subDay()]);
        Task::factory()->between($this->sectorA)->create(['status' => TaskStatus::InProgress]);
        Task::factory()->between($this->sectorA)->create(['status' => TaskStatus::Completed, 'resolved_at' => now(), 'due_at' => now()->subDays(2)]);
        Task::factory()->between($this->sectorD)->create();

        Occurrence::factory()->between($this->sectorA)->create(['priority' => DemandPriority::Urgent]);
        Occurrence::factory()->between($this->sectorA)->create();
        Occurrence::factory()->between($this->sectorA)->create(['status' => OccurrenceStatus::InProgress]);
        Occurrence::factory()->between($this->sectorA)->create(['status' => OccurrenceStatus::Resolved, 'resolved_at' => now()]);
        Occurrence::factory()->between($this->sectorD)->create(['priority' => DemandPriority::Urgent]);

        // Outro evento: não entra em nenhuma contagem.
        $other = Event::factory()->create();
        Participant::factory()->for($other)->create();
        Team::factory()->for($other)->create();
        Company::factory()->for($other)->create();
    }

    private function dashboard(User $user)
    {
        return $this->actingAs($user)->getJson("/api/v1/events/{$this->event->id}/dashboard")->assertOk()->json('data');
    }

    public function test_admin_sees_every_block_of_the_event(): void
    {
        $data = $this->dashboard($this->adminUser);

        $this->assertSame($this->event->id, $data['event_id']);
        $this->assertSame(['total' => 3, 'without_team' => 1], $data['participants']);
        $this->assertSame(['total' => 1], $data['teams']);
        $this->assertSame(['total' => 2], $data['companies']);
        $this->assertSame(['total' => 1], $data['challenges']);
        $this->assertSame(['pending' => 3, 'in_progress' => 1, 'overdue' => 1], $data['tasks']);
        $this->assertSame(['open' => 3, 'in_progress' => 1, 'urgent_open' => 2], $data['occurrences']);
    }

    public function test_sector_scoped_users_only_count_their_demands(): void
    {
        $a = $this->dashboard($this->manager('A'));
        $this->assertSame(['pending' => 2, 'in_progress' => 1, 'overdue' => 1], $a['tasks']);
        $this->assertSame(['open' => 2, 'in_progress' => 1, 'urgent_open' => 1], $a['occurrences']);

        $b = $this->dashboard($this->manager('B'));
        $this->assertSame(['pending' => 0, 'in_progress' => 0, 'overdue' => 0], $b['tasks']);
        $this->assertSame(['open' => 0, 'in_progress' => 0, 'urgent_open' => 0], $b['occurrences']);
    }

    /**
     * Cada número é o mesmo meta.total da listagem que o card abre (mesmos filtros, mesmo usuário).
     */
    public function test_counts_match_the_list_endpoints(): void
    {
        $lists = [
            ['participants', 'total', 'participants'],
            ['participants', 'without_team', 'participants?has_team=false&status=AVAILABLE'],
            ['teams', 'total', 'teams'],
            ['companies', 'total', 'companies'],
            ['challenges', 'total', 'challenges'],
            ['tasks', 'pending', 'tasks?status=PENDING'],
            ['tasks', 'in_progress', 'tasks?status=IN_PROGRESS'],
            ['tasks', 'overdue', 'tasks?overdue=1'],
            ['occurrences', 'open', 'occurrences?status=OPEN'],
            ['occurrences', 'in_progress', 'occurrences?status=IN_PROGRESS'],
            ['occurrences', 'urgent_open', 'occurrences?status=OPEN&priority=URGENT'],
        ];

        foreach ([$this->adminUser, $this->manager('A'), $this->editor('D'), $this->consultant] as $user) {
            $data = $this->dashboard($user);
            foreach ($lists as [$block, $key, $path]) {
                if ($data[$block] === null) {
                    continue;
                }
                $separator = str_contains($path, '?') ? '&' : '?';
                $total = $this->actingAs($user)->getJson("/api/v1/events/{$this->event->id}/{$path}{$separator}per_page=1")->assertOk()->json('meta.total');
                $this->assertSame($total, $data[$block][$key], "{$user->email}: {$block}.{$key}");
            }
        }
    }

    public function test_blocks_without_permission_are_null(): void
    {
        foreach ([RoleCode::Consultant, RoleCode::Juror, RoleCode::Voter] as $role) {
            $user = $this->userWithRole($role);
            $response = $this->actingAs($user)->getJson("/api/v1/events/{$this->event->id}/dashboard");
            if (! $user->hasPermission(PermissionCode::EventsView)) {
                $response->assertForbidden(); // sem events.view: nem o resumo do evento

                continue;
            }
            $data = $response->assertOk()->json('data');
            foreach (['participants' => 'participants.view', 'teams' => 'teams.view', 'companies' => 'companies.view', 'challenges' => 'challenges.view', 'tasks' => 'tasks.view', 'occurrences' => 'occurrences.view'] as $block => $permission) {
                $this->assertSame(! $user->hasPermission(PermissionCode::from($permission)), $data[$block] === null, "{$role->value}: {$block}");
            }
        }
    }

    public function test_requires_authentication(): void
    {
        $this->getJson("/api/v1/events/{$this->event->id}/dashboard")->assertUnauthorized();
    }

    public function test_exposes_no_personal_data_and_does_not_audit(): void
    {
        $logs = AuditLog::query()->count();

        $json = $this->actingAs($this->adminUser)->getJson("/api/v1/events/{$this->event->id}/dashboard")->assertOk()->getContent();

        $this->assertStringNotContainsString('Pessoa Sigilosa', $json);
        $this->assertStringNotContainsString('sigilo@teste.local', $json);
        $this->assertSame($logs, AuditLog::query()->count());
    }

    public function test_uses_a_fixed_number_of_queries(): void
    {
        // Mais dados não aumentam o número de consultas (só contagens; nada de N+1).
        Participant::factory()->count(20)->for($this->event)->create();
        Task::factory()->count(10)->between($this->sectorA)->create();

        $this->actingAs($this->adminUser);
        DB::enableQueryLog();
        $this->getJson("/api/v1/events/{$this->event->id}/dashboard")->assertOk();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(16, $queries, "consultas: {$queries}");
    }
}
