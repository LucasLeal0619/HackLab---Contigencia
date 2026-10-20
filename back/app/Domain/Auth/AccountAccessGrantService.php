<?php

namespace App\Domain\Auth;

use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditLogger;
use App\Domain\Auth\Enums\AccessGrantSource;
use App\Domain\Auth\Enums\AccessGrantStatus;
use App\Domain\Users\Enums\RoleCode;
use App\Models\AccountAccessGrant;
use App\Models\Event;
use App\Models\Person;
use App\Models\Role;
use App\Models\Sector;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Liberação de acesso (Complemento 11.2): o Administrador autoriza uma Person a ativar a própria conta com
 * um perfil/setor escolhido por ele. Não cria User, não define nem envia senha: a conta nasce no primeiro
 * acesso (AccountActivationService), que consome a liberação na mesma transação.
 *
 * - Perfis: MANAGER e EDITOR (setor ativo do evento) e CONSULTANT (sem setor). Nunca ADMINISTRATOR, JUROR, VOTER.
 * - Uma liberação PENDING por Person (o perfil do User é global; não há membership por evento).
 * - Person com conta (ou e-mail já usado por outra conta) não recebe liberação.
 */
class AccountAccessGrantService
{
    /** @var list<RoleCode> */
    public const ALLOWED_ROLES = [RoleCode::Manager, RoleCode::Editor, RoleCode::Consultant];

    public function __construct(private readonly AuditLogger $audit) {}

    public function grant(Event $event, Person $person, RoleCode $role, ?int $sectorId, User $actor, AccessGrantSource $source): AccountAccessGrant
    {
        if (! in_array($role, self::ALLOWED_ROLES, true)) {
            throw ValidationException::withMessages(['role' => 'A liberação de acesso só aceita Gestor, Editor ou Consultor.']);
        }

        $sector = $this->sectorFor($event, $role, $sectorId);

        try {
            return DB::transaction(function () use ($event, $person, $role, $sector, $actor, $source) {
                $person = Person::query()->lockForUpdate()->findOrFail($person->id);

                if (blank($person->email)) {
                    throw ValidationException::withMessages(['person' => 'Esta pessoa não tem e-mail: sem e-mail não há primeiro acesso.']);
                }

                $account = self::accountOf($person);
                if ($account !== null) {
                    throw ValidationException::withMessages(['person' => "Já possui acesso: {$account->role->code->label()}. Para mudar o perfil, use a gestão de usuários."]);
                }

                if (AccountAccessGrant::query()->where('person_id', $person->id)->where('status', AccessGrantStatus::Pending->value)->exists()) {
                    throw ValidationException::withMessages(['person' => 'Esta pessoa já tem uma liberação de acesso pendente. Revogue-a antes de liberar outra.']);
                }

                $grant = new AccountAccessGrant([
                    'event_id' => $event->id,
                    'person_id' => $person->id,
                    'role_id' => Role::forCode($role)->id,
                    'sector_id' => $sector?->id,
                    'source' => $source,
                    'granted_by_user_id' => $actor->id,
                ]);
                $grant->forceFill(['status' => AccessGrantStatus::Pending])->save();

                $this->audit->record(
                    AuditAction::ACCESS_GRANT_CREATED,
                    'users',
                    "Acesso liberado para {$person->full_name} como {$role->label()}".($sector ? " no setor {$sector->name}" : '').'.',
                    entity: $grant,
                    after: $this->snapshot($grant, $role),
                    actor: $actor,
                    event: $event,
                );

                return $grant->load('role', 'sector', 'person');
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['person' => 'Esta pessoa já tem uma liberação de acesso pendente. Revogue-a antes de liberar outra.']);
        }
    }

    /**
     * PENDING → REVOKED. Liberação já consumida não é revogada: a conta segue a gestão normal de usuários.
     */
    public function revoke(AccountAccessGrant $grant, User $actor): AccountAccessGrant
    {
        return DB::transaction(function () use ($grant, $actor) {
            $grant = AccountAccessGrant::query()->with('role', 'person')->lockForUpdate()->findOrFail($grant->id);

            if (! $grant->isPending()) {
                throw ValidationException::withMessages(['grant' => $grant->status === AccessGrantStatus::Consumed
                    ? 'Esta liberação já foi usada: a conta existe. Altere o status ou o perfil pela gestão de usuários.'
                    : 'Esta liberação já foi revogada.']);
            }

            $before = $this->snapshot($grant, $grant->role->code);
            $grant->forceFill(['status' => AccessGrantStatus::Revoked, 'revoked_at' => now(), 'revoked_by_user_id' => $actor->id])->save();

            $this->audit->record(
                AuditAction::ACCESS_GRANT_REVOKED,
                'users',
                "Liberação de acesso de {$grant->person->full_name} revogada.",
                entity: $grant,
                before: $before,
                after: $this->snapshot($grant, $grant->role->code),
                actor: $actor,
                event: $grant->event_id,
            );

            return $grant->load('sector');
        });
    }

    /**
     * Conta já existente para a Person: dela, ou de outra Person com o mesmo e-mail (o primeiro acesso recusaria).
     */
    public static function accountOf(Person $person): ?User
    {
        return User::query()->with('role')
            ->where('person_id', $person->id)
            ->when(filled($person->email), fn ($q) => $q->orWhereRaw('lower(email) = ?', [mb_strtolower($person->email)]))
            ->first();
    }

    private function sectorFor(Event $event, RoleCode $role, ?int $sectorId): ?Sector
    {
        if (! $role->requiresSector()) {
            if ($sectorId !== null) {
                throw ValidationException::withMessages(['sector_id' => "O perfil {$role->label()} não tem setor."]);
            }

            return null;
        }

        if ($sectorId === null) {
            throw ValidationException::withMessages(['sector_id' => "O perfil {$role->label()} exige um setor."]);
        }

        $sector = Sector::query()->where('event_id', $event->id)->find($sectorId);

        if ($sector === null || ! $sector->active) {
            throw ValidationException::withMessages(['sector_id' => 'Setor inexistente, inativo ou de outro evento.']);
        }

        return $sector;
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(AccountAccessGrant $grant, RoleCode $role): array
    {
        return [
            'person_id' => $grant->person_id,
            'role' => $role->value,
            'sector_id' => $grant->sector_id,
            'status' => $grant->status->value,
            'source' => $grant->source->value,
        ];
    }
}
