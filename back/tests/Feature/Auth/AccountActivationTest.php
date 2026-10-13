<?php

namespace Tests\Feature\Auth;

use App\Domain\Audit\AuditAction;
use App\Domain\Auth\AccountActivationService;
use App\Domain\Events\Enums\EventStatus;
use App\Domain\Integrations\Enums\RegistrationStatus;
use App\Domain\Integrations\Enums\TicketCategory;
use App\Domain\Users\Enums\RoleCode;
use App\Models\AccountActivationToken;
use App\Models\AuditLog;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\Juror;
use App\Models\JurorTeamAssignment;
use App\Models\Participant;
use App\Models\Sector;
use App\Models\User;
use App\Models\VotingEligibility;
use App\Notifications\AccountActivationNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\ActivationScenario;
use Tests\DatabaseTestCase;

/**
 * Primeiro acesso (Complemento 11.1): VOTER por inscrição CONFIRMED + entrada PUBLIC; JUROR só por Juror
 * interno ACTIVE; anti-enumeração; token com hash, uso único e expiração; conta existente nunca muda.
 */
class AccountActivationTest extends DatabaseTestCase
{
    use ActivationScenario;

    private const GENERIC = ['message' => 'Se este e-mail puder acessar o HackLab, enviaremos as instruções.'];

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->buildActivationScenario();
    }

    private function assertNothingSent(string $email): void
    {
        $this->requestActivation($email)->assertStatus(202)->assertExactJson(self::GENERIC);
        Notification::assertNothingSent();
        $this->assertSame(0, AccountActivationToken::query()->count());
    }

    public function test_confirmed_public_registration_gets_an_activation_email(): void
    {
        $this->voterCandidate('Publico@Teste.Local ');

        $this->requestActivation('  PUBLICO@teste.local ')->assertStatus(202)->assertExactJson(self::GENERIC);

        Notification::assertSentOnDemandTimes(AccountActivationNotification::class, 1);
        $token = $this->activationTokenFor('publico@teste.local');
        $record = AccountActivationToken::query()->sole();
        $this->assertSame('VOTER', $record->intended_role->value);
        $this->assertSame('publico@teste.local', $record->requested_email);
        $this->assertSame(AccountActivationService::hash($token), $record->token_hash);
        $this->assertSame(0, User::query()->where('email', 'publico@teste.local')->count(), 'User só nasce na conclusão');

        // O token bruto não existe em nenhuma coluna de texto do banco.
        $this->assertSame(0, DB::table('account_activation_tokens')->where('token_hash', $token)->count());
        foreach (['requested_email', 'token_hash'] as $column) {
            $this->assertSame(0, DB::table('account_activation_tokens')->where($column, 'like', "%{$token}%")->count());
        }
    }

    public function test_link_points_to_frontend_url_with_encoded_token(): void
    {
        config(['app.frontend_url' => 'https://front.exemplo.test/']);
        $this->voterCandidate();
        $this->requestActivation('publico@teste.local');
        $token = $this->activationTokenFor('publico@teste.local');

        Notification::assertSentOnDemand(AccountActivationNotification::class, function (AccountActivationNotification $n, array $c, object $notifiable) use ($token) {
            $mail = $n->toMail($notifiable);

            return $mail->subject === 'Ative seu acesso ao HackLab'
                && $mail->actionUrl === 'https://front.exemplo.test/#/ativar-conta?token='.rawurlencode($token);
        });
    }

    public function test_ineligible_situations_send_nothing_and_answer_the_same(): void
    {
        $cases = [
            'pendente@teste.local' => fn ($p) => $this->inscribe($p, 'public', RegistrationStatus::Pending),
            'cancelada@teste.local' => fn ($p) => $this->inscribe($p, 'public', RegistrationStatus::Cancelled),
            'semmapa@teste.local' => fn ($p) => $this->inscribe($p, 'unmapped'),
            'participante@teste.local' => fn ($p) => $this->inscribe($p, 'participant'),
            'juradoexterno@teste.local' => fn ($p) => $this->inscribe($p, 'juror'),
            'conflito@teste.local' => fn ($p) => $this->inscribe($p, 'public', attributes: ['identity_mismatch' => true]),
            'origemdiferente@teste.local' => fn ($p) => $this->inscribe($p, 'public', attributes: ['source_email' => 'outro@teste.local']),
        ];

        foreach ($cases as $email => $setup) {
            $setup($this->person($email));
        }

        foreach ([...array_keys($cases), 'desconhecido@teste.local'] as $email) {
            $this->assertNothingSent($email);
        }
        $this->assertSame(0, Participant::query()->count());
    }

    public function test_finished_event_does_not_grant_access(): void
    {
        $this->event->forceFill(['status' => EventStatus::Finished])->save();
        $this->voterCandidate();

        $this->assertNothingSent('publico@teste.local');
    }

    public function test_ambiguous_email_creates_nothing(): void
    {
        $this->voterCandidate('duplo@teste.local');
        $this->voterCandidate('duplo@teste.local'); // segunda Person com o mesmo e-mail

        $this->assertNothingSent('duplo@teste.local');
        $this->assertSame(0, User::query()->where('email', 'duplo@teste.local')->count());
    }

    public function test_multiple_registrations_of_the_same_person_are_one_identity(): void
    {
        $person = $this->voterCandidate();
        $this->inscribe($person);
        $otherEvent = Event::factory()->create();
        $otherIntegration = $this->even3Integration($otherEvent, 'outro-evento');
        $this->tickets['public2'] = $this->ticketType($otherIntegration, '10', 'Ingresso geral', TicketCategory::Public);
        $this->inscribe($person, 'public2', integration: $otherIntegration);

        $this->requestActivation('publico@teste.local')->assertStatus(202);
        $this->assertSame(1, AccountActivationToken::query()->count());
        Notification::assertSentOnDemandTimes(AccountActivationNotification::class, 1);

        $this->completeActivation($this->activationTokenFor('publico@teste.local'))->assertOk();
        $this->assertSame(1, User::query()->where('person_id', $person->id)->count());
    }

    public function test_completion_creates_an_active_verified_voter_without_eligibility(): void
    {
        $person = $this->voterCandidate();
        $this->requestActivation('publico@teste.local');
        $token = $this->activationTokenFor('publico@teste.local');

        $this->completeActivation($token)->assertOk()->assertJsonPath('message', 'Acesso ativado. Agora você pode entrar no HackLab.');

        $user = User::query()->with('role')->where('person_id', $person->id)->sole();
        $this->assertSame('VOTER', $user->role->code->value);
        $this->assertSame('ACTIVE', $user->status->value);
        $this->assertSame('publico@teste.local', $user->email);
        $this->assertNotNull($user->email_verified_at);
        $this->assertNull($user->sector_id);
        $this->assertTrue(Hash::check('senhaNova123', $user->password));
        $this->assertSame(0, AccountActivationToken::query()->count(), 'token consumido');

        // Primeiro acesso não dá direito a votar nem cria Juror/atribuição.
        $this->assertSame(0, VotingEligibility::query()->count());
        $this->assertSame(0, Juror::query()->count());

        $log = AuditLog::query()->where('action', AuditAction::USER_SELF_ACTIVATED)->sole();
        $this->assertSame($user->id, $log->actor_user_id);
        $this->assertStringNotContainsString($token, json_encode($log->toArray()));
        $this->assertStringNotContainsString('senhaNova123', json_encode($log->toArray()));
    }

    public function test_active_internal_juror_gets_juror_account_and_takes_precedence(): void
    {
        $person = $this->person('jurado.interno@teste.local');
        Juror::factory()->for($this->event)->create(['person_id' => $person->id]);
        $this->inscribe($person); // também PUBLIC: Juror tem precedência

        $this->requestActivation('jurado.interno@teste.local')->assertStatus(202);
        $this->completeActivation($this->activationTokenFor('jurado.interno@teste.local'))->assertOk();

        $this->assertSame('JUROR', User::query()->with('role')->where('person_id', $person->id)->sole()->role->code->value);
        $this->assertSame(0, JurorTeamAssignment::query()->count(), 'atribuições continuam manuais');
    }

    public function test_external_juror_category_or_inactive_juror_grants_nothing(): void
    {
        $external = $this->person('so.categoria@teste.local');
        $this->inscribe($external, 'juror');
        $inactive = $this->person('inativo@teste.local');
        Juror::factory()->for($this->event)->inactive()->create(['person_id' => $inactive->id]);

        $this->assertNothingSent('so.categoria@teste.local');
        $this->assertNothingSent('inativo@teste.local');
        $this->assertSame(0, Juror::query()->where('person_id', $external->id)->count(), 'categoria externa não cria Juror');
    }

    public function test_existing_account_is_never_changed(): void
    {
        $manager = User::factory()->role(RoleCode::Manager)->inSector(Sector::factory()->for($this->event)->create())->create(['email' => 'gestor@teste.local']);
        $manager->person->forceFill(['email' => 'gestor@teste.local'])->save();
        $this->inscribe($manager->person);

        $this->assertNothingSent('gestor@teste.local');
        $this->assertSame('MANAGER', $manager->fresh()->role->code->value);
        $this->assertSame(1, User::query()->where('person_id', $manager->person_id)->count());
    }

    public function test_email_already_used_by_another_persons_account_is_not_merged(): void
    {
        $other = $this->userWithRole(RoleCode::Editor, ['email' => 'compartilhado@teste.local']);
        $person = $this->voterCandidate('compartilhado@teste.local');

        $this->assertNothingSent('compartilhado@teste.local');
        $this->assertSame($other->person_id, User::query()->where('email', 'compartilhado@teste.local')->sole()->person_id);
        $this->assertSame(0, User::query()->where('person_id', $person->id)->count());
    }

    public function test_invalid_expired_used_and_replaced_tokens_are_refused(): void
    {
        $this->voterCandidate();
        $invalid = ['token' => [AccountActivationService::INVALID_MESSAGE]];

        $this->completeActivation('token-inexistente')->assertUnprocessable()->assertJsonPath('errors', $invalid);

        // Segundo pedido invalida o primeiro.
        $this->requestActivation('publico@teste.local');
        $this->requestActivation('publico@teste.local');
        [$first, $second] = $this->activationTokensFor('publico@teste.local');
        $this->assertSame(1, AccountActivationToken::query()->count());
        $this->completeActivation($first)->assertUnprocessable()->assertJsonPath('errors', $invalid);

        // Expirado.
        $this->travel(AccountActivationService::EXPIRES_MINUTES + 1)->minutes();
        $this->completeActivation($second)->assertUnprocessable()->assertJsonPath('errors', $invalid);
        $this->travelBack();

        // Válido, depois reutilizado.
        $this->requestActivation('publico@teste.local');
        $token = $this->activationTokenFor('publico@teste.local');
        $this->completeActivation($token)->assertOk();
        $this->completeActivation($token, 'outraSenha456')->assertUnprocessable()->assertJsonPath('errors', $invalid);
        $this->assertSame(1, User::query()->where('email', 'publico@teste.local')->count());
    }

    public function test_password_policy_and_confirmation(): void
    {
        $this->voterCandidate();
        $this->requestActivation('publico@teste.local');
        $token = $this->activationTokenFor('publico@teste.local');

        $this->completeActivation($token, 'curta1')->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->completeActivation($token, 'semnumeros')->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->completeActivation($token, 'senhaNova123', 'diferente123')->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->assertSame(1, AccountActivationToken::query()->count(), 'validação não consome o token');
        $this->completeActivation($token)->assertOk();
    }

    public function test_eligibility_is_revalidated_on_completion(): void
    {
        $person = $this->voterCandidate();
        $this->requestActivation('publico@teste.local');
        $token = $this->activationTokenFor('publico@teste.local');

        // A inscrição foi cancelada na Even3 depois do pedido.
        EventRegistration::query()->where('person_id', $person->id)->update(['status' => RegistrationStatus::Cancelled->value]);

        $this->completeActivation($token)->assertUnprocessable();
        $this->assertSame(0, User::query()->where('person_id', $person->id)->count());
        $this->assertSame(0, AccountActivationToken::query()->count());
    }

    public function test_public_responses_do_not_reveal_anything(): void
    {
        $this->voterCandidate('elegivel@teste.local');
        $this->inscribe($this->person('inelegivel@teste.local'), 'participant');
        $this->userWithRole(RoleCode::Voter, ['email' => 'comconta@teste.local']);

        $responses = collect(['elegivel@teste.local', 'inelegivel@teste.local', 'comconta@teste.local', 'nao.existe@teste.local'])
            ->map(fn ($email) => [$this->requestActivation($email)->status(), $this->requestActivation($email)->getContent()])
            ->unique()
            ->values();

        $this->assertCount(1, $responses);
        $this->assertSame(202, $responses[0][0]);
    }

    public function test_request_is_rate_limited_without_affecting_login(): void
    {
        foreach (range(1, 5) as $i) {
            $this->requestActivation('alvo@teste.local')->assertStatus(202);
        }
        $this->requestActivation('alvo@teste.local')->assertStatus(429);

        // Login usa o limitador próprio.
        $this->fromSpa()->postJson('/api/v1/auth/login', ['email' => 'alvo@teste.local', 'password' => 'qualquer1'])->assertUnprocessable();
    }

    public function test_sync_style_data_alone_never_creates_users(): void
    {
        $this->voterCandidate();

        $this->assertSame(0, User::query()->where('email', 'publico@teste.local')->count());
    }
}
