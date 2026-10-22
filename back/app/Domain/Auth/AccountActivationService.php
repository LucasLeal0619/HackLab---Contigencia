<?php

namespace App\Domain\Auth;

use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditLogger;
use App\Domain\Users\Enums\UserStatus;
use App\Models\AccountActivationToken;
use App\Models\Person;
use App\Models\Role;
use App\Models\User;
use App\Notifications\AccountActivationNotification;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * Primeiro acesso (Complemento 11.1): a própria pessoa comprova a posse do e-mail e define a senha.
 *
 * - Pedido: se elegível, gera um token aleatório (só o HMAC fica no banco), substitui o pedido anterior da
 *   Person e envia o link. Se não, não faz nada. O chamador responde sempre igual (anti-enumeração).
 * - Conclusão: em transação, com lock do token e da Person, revalida tudo e só então cria o User.
 */
class AccountActivationService
{
    public const EXPIRES_MINUTES = 60;

    public const INVALID_MESSAGE = 'Link de ativação inválido ou expirado. Peça um novo primeiro acesso.';

    public function __construct(
        private readonly AccountActivationEligibility $eligibility,
        private readonly AuditLogger $audit,
    ) {}

    public static function hash(string $token): string
    {
        return hash_hmac('sha256', $token, (string) config('app.key'));
    }

    /**
     * Pedido de primeiro acesso. Não revela nada: devolve void em qualquer caso.
     */
    public function request(string $email): void
    {
        $email = AccountActivationEligibility::normalize($email);
        $eligible = $this->eligibility->resolve($email);
        if ($eligible === null) {
            return;
        }

        $token = bin2hex(random_bytes(32));

        DB::transaction(function () use ($eligible, $email, $token) {
            Person::query()->whereKey($eligible['person']->id)->lockForUpdate()->first();
            // Um pedido vigente por Person: o novo substitui (e invalida) o anterior.
            AccountActivationToken::query()->where('person_id', $eligible['person']->id)->delete();
            AccountActivationToken::query()->create([
                'person_id' => $eligible['person']->id,
                'requested_email' => $email,
                'intended_role' => $eligible['role']->value,
                'token_hash' => self::hash($token),
                'expires_at' => now()->addMinutes(self::EXPIRES_MINUTES),
            ]);
        });

        Notification::route('mail', $email)->notify(new AccountActivationNotification($token, self::EXPIRES_MINUTES));
    }

    /**
     * Conclui o primeiro acesso e cria o User. Qualquer falha devolve a mesma mensagem genérica.
     */
    public function complete(string $token, string $password): User
    {
        try {
            // Casos inválidos devolvem null (com o token já removido, confirmado no commit) e só então viram erro.
            $user = DB::transaction(function () use ($token, $password) {
                $record = AccountActivationToken::query()->where('token_hash', self::hash($token))->lockForUpdate()->first();
                if ($record === null) {
                    return null;
                }

                if ($record->isExpired()) {
                    $record->delete();

                    return null;
                }

                $person = Person::query()->whereKey($record->person_id)->lockForUpdate()->first();
                $eligible = $person === null ? null : $this->eligibility->resolveFor($person, $record->requested_email);
                // Perfil diferente do pedido (jurado inativado, inscrição cancelada…): o link deixa de valer.
                if ($eligible === null || $eligible['role'] !== $record->intended_role) {
                    // A situação mudou desde o pedido (conta criada, inscrição cancelada, conflito): link sem efeito.
                    $record->delete();

                    return null;
                }

                $user = User::query()->create([
                    'person_id' => $person->id,
                    'role_id' => Role::forCode($eligible['role'])->id,
                    'sector_id' => null,
                    'email' => $record->requested_email,
                    'password' => $password,
                    'status' => UserStatus::Active->value,
                    // O link enviado a este endereço provou a posse da caixa postal.
                    'email_verified_at' => now(),
                ]);

                $record->delete();

                $this->audit->record(
                    AuditAction::USER_SELF_ACTIVATED,
                    'auth',
                    "Primeiro acesso concluído por {$person->full_name} ({$eligible['role']->label()}).",
                    entity: $user,
                    after: ['email' => $user->email, 'role' => $eligible['role']->value, 'status' => UserStatus::Active->value, 'person_id' => $person->id],
                    actor: $user,
                );

                return $user->load('person', 'role');
            });
        } catch (UniqueConstraintViolationException) {
            // Última linha de defesa: users.person_id/users.email únicos (corrida improvável depois dos locks).
            // A exceção do Laravel cobre PostgreSQL (23505) e MySQL (1062).
            throw $this->invalid();
        }

        return $user ?? throw $this->invalid();
    }

    private function invalid(): ValidationException
    {
        return ValidationException::withMessages(['token' => self::INVALID_MESSAGE]);
    }
}
