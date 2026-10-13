<?php

namespace Tests\Feature\Voting;

use App\Domain\Audit\AuditAction;
use App\Domain\Integrations\Enums\RegistrationStatus;
use App\Domain\Integrations\Enums\TicketCategory;
use App\Domain\Users\Enums\RoleCode;
use App\Domain\Users\Enums\UserStatus;
use App\Domain\Voting\VotingSessionService;
use App\Models\AuditLog;
use App\Models\Event;
use App\Models\Person;
use App\Models\Team;
use App\Models\User;
use App\Models\VotingEligibility;
use App\Models\VotingSession;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\ActivationScenario;
use Tests\Concerns\VotingScenario;
use Tests\DatabaseTestCase;

/**
 * Preparação dos Votantes do público (Complemento 11.1): adiciona em lote, numa sessão DRAFT, as contas VOTER
 * ACTIVE cuja Person tem inscrição CONFIRMED + PUBLIC (sem identity_mismatch) no evento da sessão.
 */
class PublicVoterEnrollmentTest extends DatabaseTestCase
{
    use ActivationScenario;
    use VotingScenario;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->buildActivationScenario();
        $this->team = Team::factory()->for($this->event)->create();
    }

    private function account(string $email, RoleCode $role = RoleCode::Voter, array $attributes = []): User
    {
        $person = $this->person($email);

        return User::factory()->role($role)->create(['person_id' => $person->id, 'email' => $email] + $attributes);
    }

    private function add(VotingSession $session, ?User $actor = null)
    {
        return $this->actingAs($actor ?? $this->admin())->postJson("/api/v1/voting-sessions/{$session->id}/eligibilities/public-voters");
    }

    private function readiness(VotingSession $session)
    {
        return $this->actingAs($this->admin())->getJson("/api/v1/voting-sessions/{$session->id}/public-voters/readiness")->assertOk()->json('data');
    }

    private function eligibleEmails(VotingSession $session): array
    {
        return VotingEligibility::query()->where('voting_session_id', $session->id)->join('users', 'users.id', '=', 'voting_eligibilities.user_id')
            ->orderBy('users.email')->pluck('users.email')->all();
    }

    public function test_only_activated_public_voters_of_the_session_event_are_added(): void
    {
        $ok = $this->account('ok@teste.local');
        $this->inscribe($ok->person);
        $this->inscribe($ok->person); // duas inscrições da mesma Person: conta uma vez

        $this->inscribe($this->person('semconta@teste.local'));                                        // sem conta: contador
        $this->inscribe($this->account('inativa@teste.local', attributes: ['status' => UserStatus::Inactive])->person);
        $this->inscribe($this->account('editor@teste.local', RoleCode::Consultant)->person);         // outro perfil
        $this->inscribe($this->account('participante@teste.local')->person, 'participant');
        $this->inscribe($this->account('jurado@teste.local')->person, 'juror');
        $this->inscribe($this->account('conflito@teste.local')->person, attributes: ['identity_mismatch' => true]);
        $this->inscribe($this->account('pendente@teste.local')->person, status: RegistrationStatus::Pending);
        $this->inscribe($this->account('cancelada@teste.local')->person, status: RegistrationStatus::Cancelled);
        $this->inscribe($this->account('semmapa@teste.local')->person, 'unmapped');

        // Mesmo perfil e PUBLIC, mas em outro evento.
        $other = Event::factory()->create();
        $otherIntegration = $this->even3Integration($other, 'outro-evento');
        $this->tickets['public-other'] = $this->ticketType($otherIntegration, '10', 'Ingresso geral', TicketCategory::Public);
        $this->inscribe($this->account('outroevento@teste.local')->person, 'public-other', integration: $otherIntegration);

        // Elegível manual que não é do público: precisa continuar.
        $manual = $this->voter('Votante manual');
        $session = $this->draftSession($this->event, [$this->team], [$manual]);

        $readiness = $this->readiness($session);
        $this->assertSame([
            'public_confirmed_people' => 4,      // ok, semconta, inativa, editor
            'activated_voter_accounts' => 1,
            'already_eligible' => 0,
            'public_without_account' => 1,
            'public_with_other_account' => 2,
            'eligible_voters' => 1,
            'candidates' => 1,
        ], $readiness);

        $this->add($session)->assertOk()->assertJsonPath('data', [
            'eligible_public_accounts' => 1,
            'already_eligible' => 0,
            'added' => 1,
            'public_without_account' => 1,
        ]);

        $expected = ['ok@teste.local', $manual->email];
        sort($expected);
        $this->assertSame($expected, $this->eligibleEmails($session), 'manual preservado + 1 do público');

        $log = AuditLog::query()->where('action', AuditAction::VOTING_ELIGIBILITY_CHANGED)->latest('id')->first();
        $this->assertSame([$ok->id], $log->after_data['added']);
        $this->assertSame('PUBLIC_ACTIVATED_VOTERS', $log->after_data['source']);
    }

    public function test_second_run_adds_nothing_and_does_not_audit(): void
    {
        $this->inscribe($this->account('ok@teste.local')->person);
        $session = $this->draftSession($this->event, [$this->team], []);

        $this->add($session)->assertOk()->assertJsonPath('data.added', 1);
        $logs = AuditLog::query()->where('action', AuditAction::VOTING_ELIGIBILITY_CHANGED)->count();

        $this->add($session)->assertOk()->assertJsonPath('data', [
            'eligible_public_accounts' => 1, 'already_eligible' => 1, 'added' => 0, 'public_without_account' => 0,
        ]);
        $this->assertSame(1, VotingEligibility::query()->where('voting_session_id', $session->id)->count());
        $this->assertSame($logs, AuditLog::query()->where('action', AuditAction::VOTING_ELIGIBILITY_CHANGED)->count());
    }

    public function test_open_or_closed_session_is_locked(): void
    {
        $voter = $this->account('ok@teste.local');
        $this->inscribe($voter->person);
        $open = $this->openSession($this->event, [$this->team], [$this->voter()]);

        $this->add($open)->assertUnprocessable()->assertJsonValidationErrors('session');

        $closed = $this->closeSession($open);
        $this->add($closed)->assertUnprocessable();
        $this->assertSame(0, VotingEligibility::query()->where('user_id', $voter->id)->count());
    }

    public function test_requires_voting_eligibility_manage(): void
    {
        $session = $this->draftSession($this->event, [$this->team], []);

        foreach ([RoleCode::Consultant, RoleCode::Voter, RoleCode::Juror] as $role) {
            $this->add($session, $this->userWithRole($role))->assertForbidden();
        }
        $this->actingAs($this->userWithRole(RoleCode::Voter))->getJson("/api/v1/voting-sessions/{$session->id}/public-voters/readiness")->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->postJson("/api/v1/voting-sessions/{$session->id}/eligibilities/public-voters")->assertUnauthorized();
    }

    public function test_first_access_after_opening_does_not_join_the_open_session(): void
    {
        $early = $this->voterCandidate('cedo@teste.local');
        $this->requestActivation('cedo@teste.local');
        $this->completeActivation($this->activationTokenFor('cedo@teste.local'))->assertOk();

        $session = $this->draftSession($this->event, [$this->team], []);
        $this->add($session)->assertOk()->assertJsonPath('data.added', 1);
        app(VotingSessionService::class)->open($session->refresh());

        // Ativou a conta depois da abertura: a conta nasce, mas não entra na sessão aberta.
        $late = $this->voterCandidate('tarde@teste.local');
        $this->requestActivation('tarde@teste.local');
        $this->completeActivation($this->activationTokenFor('tarde@teste.local'))->assertOk();

        $lateUser = User::query()->where('person_id', $late->id)->sole();
        $this->assertSame('VOTER', $lateUser->role->code->value);
        $this->assertSame(0, VotingEligibility::query()->where('user_id', $lateUser->id)->count());
        $this->assertSame(1, VotingEligibility::query()->where('voting_session_id', $session->id)->count());
        $this->assertSame(1, VotingEligibility::query()->whereIn('user_id', User::query()->where('person_id', $early->id)->pluck('id'))->count());
        $this->add($session->refresh())->assertUnprocessable();
    }

    public function test_readiness_counts_people_once_and_exposes_no_personal_data(): void
    {
        $person = Person::factory()->create(['email' => 'varias@teste.local', 'full_name' => 'Pessoa Sigilosa']);
        $this->inscribe($person);
        $this->inscribe($person);
        $session = $this->draftSession($this->event, [$this->team], []);

        $data = $this->readiness($session);

        $this->assertSame(1, $data['public_confirmed_people']);
        $this->assertSame(1, $data['public_without_account']);
        $this->assertStringNotContainsString('varias@teste.local', json_encode($data));
        $this->assertStringNotContainsString('Pessoa Sigilosa', json_encode($data));
    }
}
