<?php

namespace Tests\Feature\Database;

use App\Domain\Voting\VotingAggregates;
use App\Models\Challenge;
use App\Models\Company;
use App\Models\CompanyRepresentative;
use App\Models\Evaluation;
use App\Models\EvaluationCriterion;
use App\Models\Event;
use App\Models\EventDay;
use App\Models\Juror;
use App\Models\JurorTeamAssignment;
use App\Models\Meeting;
use App\Models\Occurrence;
use App\Models\Participant;
use App\Models\PublicVote;
use App\Models\ResultPublication;
use App\Models\Role;
use App\Models\SchoolClass;
use App\Models\Sector;
use App\Models\Task;
use App\Models\Team;
use App\Models\TeamMember;
use App\Models\User;
use App\Models\VotingEligibility;
use App\Models\VotingSession;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DevelopmentAdminSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SeedTest extends TestCase
{
    use RefreshDatabase;

    public function test_seed_creates_exactly_the_six_profiles(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertEqualsCanonicalizing(
            ['ADMINISTRATOR', 'MANAGER', 'EDITOR', 'CONSULTANT', 'JUROR', 'VOTER'],
            Role::query()->pluck('code')->map->value->all(),
        );
        $this->assertSame('Administrador', Role::query()->where('code', 'ADMINISTRATOR')->value('name'));
        $this->assertSame(0, Role::query()->where('code', 'VALIDATOR')->count());
    }

    public function test_seed_is_idempotent(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(6, Role::query()->count());
        $this->assertSame(9, User::query()->count()); // admin + gestor + editor + jurado + 5 votantes de exemplo
        $this->assertSame(1, Event::query()->count());
        $this->assertSame(3, EventDay::query()->count());
        $this->assertSame(3, Sector::query()->count()); // setores de exemplo A, B e C
        $this->assertSame(1, Meeting::query()->count());
        $this->assertSame(3, SchoolClass::query()->count());
        $this->assertSame(12, Participant::query()->count());
        $this->assertSame(2, Team::query()->count());
        $this->assertSame(8, TeamMember::query()->where('active', true)->count());
        $this->assertSame(3, Company::query()->count());
        $this->assertSame(3, CompanyRepresentative::query()->count());
        $this->assertSame(5, Challenge::query()->count());
        $this->assertSame(1, Team::query()->whereNotNull('challenge_id')->count());
        $this->assertSame(5, Task::query()->count());
        $this->assertSame(4, Occurrence::query()->count());
        $this->assertSame(1, Task::query()->whereNotNull('source_occurrence_id')->count());
        $this->assertSame(3, Juror::query()->count());
        $this->assertSame(4, JurorTeamAssignment::query()->count());
        $this->assertSame(3, EvaluationCriterion::query()->count());
        $this->assertSame(2, Evaluation::query()->count());
        $this->assertSame(2, VotingSession::query()->count());
        $this->assertSame(['CLOSED', 'DRAFT'], VotingSession::query()->orderBy('status')->pluck('status')->map->value->all());
        $this->assertSame(4, DB::table('voting_session_teams')->count());
        $this->assertSame(10, VotingEligibility::query()->count());
        $this->assertSame(4, PublicVote::query()->count());
        $this->assertSame(0, ResultPublication::query()->count());
    }

    public function test_seed_voting_demonstrates_a_popular_tie_without_creating_accounts_from_eligibility(): void
    {
        $this->seed(DatabaseSeeder::class);

        $closed = VotingSession::query()->where('status', 'CLOSED')->sole();
        $results = app(VotingAggregates::class)->results($closed);

        $this->assertSame([2, 2], array_column($results['teams'], 'vote_count'));
        $this->assertSame([1, 1], array_column($results['teams'], 'rank'));
        $this->assertTrue($results['has_ties']);
        $this->assertSame(80.0, app(VotingAggregates::class)->turnout($closed)['turnout_percent']);

        // Toda elegibilidade aponta para um User VOTER existente criado pelo seeder (nenhum outro perfil).
        $this->assertSame(0, VotingEligibility::query()->whereHas('user.role', fn ($q) => $q->where('code', '<>', 'VOTER'))->count());
    }

    public function test_development_admin_exists_in_testing_linked_to_a_person(): void
    {
        $this->seed(DatabaseSeeder::class);

        $admin = User::query()->where('email', DevelopmentAdminSeeder::DEFAULT_EMAIL)->sole();
        $this->assertSame('ADMINISTRATOR', $admin->role->code->value);
        $this->assertNotNull($admin->person);
        $this->assertTrue(Hash::check(DevelopmentAdminSeeder::DEFAULT_PASSWORD, $admin->password));
    }

    public function test_development_admin_is_never_created_in_production(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $this->app['env'] = 'production';

        // Direto no seeder: em produção o db:seed ainda pediria confirmação interativa.
        app(DevelopmentAdminSeeder::class)->run();

        $this->app['env'] = 'testing';
        $this->assertSame(0, User::query()->count());
    }
}
