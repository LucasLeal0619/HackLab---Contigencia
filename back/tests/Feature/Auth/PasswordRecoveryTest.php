<?php

namespace Tests\Feature\Auth;

use App\Domain\Audit\AuditAction;
use App\Domain\Auth\PasswordRecoveryService;
use App\Domain\Users\Enums\RoleCode;
use App\Domain\Users\Enums\UserStatus;
use App\Models\AccountActivationToken;
use App\Models\AuditLog;
use App\Models\Person;
use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\DatabaseTestCase;

/**
 * Recuperação de senha (Password Broker do Laravel): resposta genérica, link para o frontend,
 * token de uso único com expiração, nova senha com a política padrão e sessões antigas encerradas.
 */
class PasswordRecoveryTest extends DatabaseTestCase
{
    private const GENERIC = ['message' => 'Se houver uma conta com este e-mail, enviaremos um link para redefinir a senha.'];

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->user = $this->userWithRole(RoleCode::Voter, ['email' => 'conta@teste.local', 'password' => 'senhaAntiga123']);
    }

    private function forgot(string $email)
    {
        return $this->postJson('/api/v1/auth/password/forgot', ['email' => $email]);
    }

    private function reset(string $token, string $password = 'senhaNova456', ?string $email = null, ?string $confirmation = null)
    {
        return $this->postJson('/api/v1/auth/password/reset', [
            'email' => $email ?? 'conta@teste.local', 'token' => $token, 'password' => $password, 'password_confirmation' => $confirmation ?? $password,
        ]);
    }

    private function tokenFromMail(): string
    {
        $token = null;
        Notification::assertSentTo($this->user, ResetPasswordNotification::class, function (ResetPasswordNotification $n) use (&$token) {
            $token = $n->token;

            return true;
        });

        return $token;
    }

    private function login(string $password)
    {
        return $this->fromSpa()->postJson('/api/v1/auth/login', ['email' => 'conta@teste.local', 'password' => $password]);
    }

    public function test_existing_user_gets_a_link_to_the_frontend(): void
    {
        config(['app.frontend_url' => 'https://front.exemplo.test']);

        $this->forgot(' CONTA@teste.local ')->assertStatus(202)->assertExactJson(self::GENERIC);

        $token = $this->tokenFromMail();
        Notification::assertSentTo($this->user, ResetPasswordNotification::class, function (ResetPasswordNotification $n) use ($token) {
            $mail = $n->toMail($this->user);

            return $mail->subject === 'Redefinição de senha do HackLab'
                && $mail->actionUrl === 'https://front.exemplo.test/#/redefinir-senha?token='.rawurlencode($token).'&email='.rawurlencode('conta@teste.local');
        });
        // Só o hash fica no banco.
        $this->assertSame(0, DB::table('password_reset_tokens')->where('token', $token)->count());
    }

    public function test_unknown_or_inactive_accounts_get_the_same_answer_and_no_mail(): void
    {
        $inactive = $this->userWithRole(RoleCode::Voter, ['email' => 'inativa@teste.local', 'status' => UserStatus::Inactive]);

        $responses = collect(['conta@teste.local', 'nao.existe@teste.local', 'inativa@teste.local'])
            ->map(fn ($email) => [$this->forgot($email)->status(), $this->forgot($email)->getContent()])->unique()->values();

        $this->assertCount(1, $responses);
        $this->assertSame([202, json_encode(self::GENERIC)], $responses[0]);
        Notification::assertNotSentTo($inactive, ResetPasswordNotification::class);
        Notification::assertSentTimes(ResetPasswordNotification::class, 1); // só a conta ativa (o 2º pedido em < 60 s é ignorado)
    }

    public function test_valid_token_changes_password_and_revokes_sessions(): void
    {
        $oldRemember = $this->user->remember_token;
        DB::table('sessions')->insert([
            ['id' => 'sessao-antiga-1', 'user_id' => $this->user->id, 'payload' => 'x', 'last_activity' => time()],
            ['id' => 'sessao-antiga-2', 'user_id' => $this->user->id, 'payload' => 'x', 'last_activity' => time()],
            ['id' => 'sessao-de-outro', 'user_id' => $this->admin()->id, 'payload' => 'x', 'last_activity' => time()],
        ]);

        $this->forgot('conta@teste.local');
        $token = $this->tokenFromMail();
        $this->reset($token)->assertOk()->assertJsonPath('message', 'Senha redefinida. Entre com a nova senha.');

        $user = $this->user->fresh();
        $this->assertTrue(Hash::check('senhaNova456', $user->password));
        $this->assertFalse(Hash::check('senhaAntiga123', $user->password));
        $this->assertNotSame($oldRemember, $user->remember_token);
        $this->assertSame(0, DB::table('sessions')->where('user_id', $this->user->id)->count(), 'sessões antigas revogadas');
        $this->assertSame(1, DB::table('sessions')->where('id', 'sessao-de-outro')->count());

        $log = AuditLog::query()->where('action', AuditAction::PASSWORD_RESET_COMPLETED)->sole();
        $this->assertStringNotContainsString($token, json_encode($log->toArray()));
        $this->assertStringNotContainsString('senhaNova456', json_encode($log->toArray()));

        // Uso único.
        $this->reset($token, 'outraSenha789')->assertUnprocessable();

        // Login: antiga falha, nova funciona.
        $this->login('senhaAntiga123')->assertUnprocessable();
        $this->login('senhaNova456')->assertOk();
    }

    public function test_invalid_expired_and_weak_are_refused(): void
    {
        $invalid = ['token' => [PasswordRecoveryService::INVALID_MESSAGE]];
        $this->forgot('conta@teste.local');
        $token = $this->tokenFromMail();

        $this->reset('token-errado')->assertUnprocessable()->assertJsonPath('errors', $invalid);
        $this->reset($token, 'curta1')->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->reset($token, 'semnumeros')->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->reset($token, 'senhaNova456', 'nao.existe@teste.local')->assertUnprocessable()->assertJsonPath('errors', $invalid);

        $this->travel(61)->minutes();
        $this->reset($token)->assertUnprocessable()->assertJsonPath('errors', $invalid);
        $this->travelBack();

        $this->assertTrue(Hash::check('senhaAntiga123', $this->user->fresh()->password));
    }

    public function test_forgot_is_rate_limited(): void
    {
        foreach (range(1, 5) as $i) {
            $this->forgot('alvo@teste.local')->assertStatus(202);
        }
        $this->forgot('alvo@teste.local')->assertStatus(429);
    }

    public function test_prune_command_removes_expired_tokens(): void
    {
        $this->forgot('conta@teste.local');
        $this->assertSame(1, DB::table('password_reset_tokens')->count());
        $person = Person::factory()->create();
        AccountActivationToken::query()->create(['person_id' => $person->id, 'requested_email' => 'x@teste.local', 'intended_role' => 'VOTER', 'token_hash' => str_repeat('a', 64), 'expires_at' => now()->addHour()]);
        $this->travel(2)->hours();
        $fresh = AccountActivationToken::query()->create(['person_id' => Person::factory()->create()->id, 'requested_email' => 'y@teste.local', 'intended_role' => 'VOTER', 'token_hash' => str_repeat('b', 64), 'expires_at' => now()->addHour()]);

        $this->artisan('hacklab:prune-auth-tokens')->assertSuccessful();

        $this->assertSame(0, DB::table('password_reset_tokens')->count());
        $this->assertSame([$fresh->id], AccountActivationToken::query()->pluck('id')->all(), 'só os vencidos saem');
    }
}
