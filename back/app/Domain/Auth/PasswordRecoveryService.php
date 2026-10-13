<?php

namespace App\Domain\Auth;

use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditLogger;
use App\Domain\Users\Enums\UserStatus;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Recuperação de senha pelo Password Broker oficial do Laravel (password_reset_tokens, token com hash).
 * O pedido nunca revela se o e-mail tem conta. A redefinição troca a senha, renova o remember_token
 * e apaga todas as sessões da conta.
 */
class PasswordRecoveryService
{
    public const INVALID_MESSAGE = 'Link de redefinição inválido ou expirado. Peça um novo link.';

    public function __construct(private readonly AuditLogger $audit) {}

    public function forgot(string $email): void
    {
        $email = AccountActivationEligibility::normalize($email);
        $user = User::query()->where('email', $email)->first();

        // Conta inexistente ou inativa: nada é enviado (a resposta pública é a mesma).
        if ($user === null || $user->status !== UserStatus::Active) {
            return;
        }

        // RESET_THROTTLED (novo pedido em menos de 60 s) também é silencioso.
        Password::broker()->sendResetLink(['email' => $email]);
    }

    public function reset(string $email, string $token, string $password): void
    {
        $email = AccountActivationEligibility::normalize($email);
        $user = User::query()->where('email', $email)->first();
        if ($user === null || $user->status !== UserStatus::Active) {
            throw $this->invalid();
        }

        $status = Password::broker()->reset(
            ['email' => $email, 'token' => $token, 'password' => $password],
            function (User $user, string $password) {
                DB::transaction(function () use ($user, $password) {
                    $user->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();
                    // Sessões abertas da conta deixam de valer.
                    DB::table('sessions')->where('user_id', $user->id)->delete();

                    $this->audit->record(
                        AuditAction::PASSWORD_RESET_COMPLETED,
                        'auth',
                        'Senha redefinida pelo link enviado ao e-mail da conta.',
                        entity: $user,
                        actor: $user,
                    );
                });
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw $this->invalid();
        }
    }

    private function invalid(): ValidationException
    {
        return ValidationException::withMessages(['token' => self::INVALID_MESSAGE]);
    }
}
