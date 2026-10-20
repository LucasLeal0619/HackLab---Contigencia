<?php

namespace App\Domain\Auth;

use App\Domain\Auth\Enums\AccessGrantStatus;
use App\Domain\Events\Enums\EventStatus;
use App\Domain\Integrations\Enums\RegistrationStatus;
use App\Domain\Integrations\Enums\TicketCategory;
use App\Domain\Jurors\Enums\JurorStatus;
use App\Domain\Users\Enums\RoleCode;
use App\Models\AccountAccessGrant;
use App\Models\EventRegistration;
use App\Models\Juror;
use App\Models\Person;
use App\Models\User;

/**
 * Quem pode fazer o primeiro acesso e com qual perfil (Complemento 11.1).
 *
 * A Even3 fornece identidade/inscrição; o HackLab decide a autoridade, só com dados internos:
 * 1. exatamente uma Person com o e-mail, sem User, e nenhum User de outra Person com esse e-mail;
 * 2. liberação de acesso PENDING (Complemento 11.2) → perfil/setor escolhidos pelo Administrador.
 *    Tem precedência sobre Jurado e Público por ser decisão explícita. Liberação pendente que não vale mais
 *    (evento encerrado, setor inativo) bloqueia o primeiro acesso até o Administrador revogar/refazer;
 * 3. Juror interno ACTIVE (evento planejado ou em andamento) → JUROR;
 * 4. senão, inscrição CONFIRMED sem identity_mismatch em entrada mapeada como PUBLIC
 *    (evento planejado ou em andamento) e e-mail de origem compatível → VOTER;
 * 5. senão, nada. Nunca por texto do nome da entrada, nunca pela categoria externa "Jurado"/"Organização".
 */
class AccountActivationEligibility
{
    /**
     * @return array{person: Person, role: RoleCode, sector_id: ?int, grant: ?AccountAccessGrant}|null
     */
    public function resolve(string $email): ?array
    {
        $email = self::normalize($email);
        if ($email === '') {
            return null;
        }

        // Ambiguidade: mais de uma Person com o mesmo e-mail → ninguém (nunca escolher arbitrariamente).
        $people = Person::query()->where('email', $email)->limit(2)->get();
        if ($people->count() !== 1) {
            return null;
        }

        $person = $people->first();

        return $this->resolveFor($person, $email);
    }

    /**
     * Revalidação para uma Person já conhecida (na conclusão do primeiro acesso).
     *
     * @return array{person: Person, role: RoleCode, sector_id: ?int, grant: ?AccountAccessGrant}|null
     */
    public function resolveFor(Person $person, string $email): ?array
    {
        $email = self::normalize($email);

        if ($person->email !== $email || Person::query()->where('email', $email)->count() !== 1) {
            return null;
        }

        // Conta existente (desta ou de outra Person com o mesmo e-mail): nunca cria outra nem troca perfil.
        if (User::query()->where('person_id', $person->id)->orWhere('email', $email)->exists()) {
            return null;
        }

        $grant = AccountAccessGrant::query()->with('role', 'sector', 'event')
            ->where('person_id', $person->id)->where('status', AccessGrantStatus::Pending->value)->first();

        if ($grant !== null) {
            return $this->grantIsUsable($grant)
                ? ['person' => $person, 'role' => $grant->role->code, 'sector_id' => $grant->sector_id, 'grant' => $grant]
                : null;
        }

        if ($this->isActiveJuror($person)) {
            return ['person' => $person, 'role' => RoleCode::Juror, 'sector_id' => null, 'grant' => null];
        }

        if ($this->hasPublicConfirmedRegistration($person, $email)) {
            return ['person' => $person, 'role' => RoleCode::Voter, 'sector_id' => null, 'grant' => null];
        }

        return null;
    }

    private function grantIsUsable(AccountAccessGrant $grant): bool
    {
        if (! in_array($grant->event?->status?->value, self::openEventStatuses(), true)) {
            return false;
        }

        if (! in_array($grant->role->code, AccountAccessGrantService::ALLOWED_ROLES, true)) {
            return false;
        }

        // Setor: obrigatório para Gestor/Editor, ativo e do mesmo evento (a FK composta garante o evento).
        return $grant->role->code->requiresSector()
            ? $grant->sector !== null && $grant->sector->active
            : $grant->sector_id === null;
    }

    public static function normalize(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    private function isActiveJuror(Person $person): bool
    {
        return Juror::query()
            ->where('person_id', $person->id)
            ->where('status', JurorStatus::Active->value)
            ->whereHas('event', fn ($q) => $q->whereIn('status', self::openEventStatuses()))
            ->exists();
    }

    private function hasPublicConfirmedRegistration(Person $person, string $email): bool
    {
        return EventRegistration::query()
            ->where('person_id', $person->id)
            ->where('status', RegistrationStatus::Confirmed->value)
            ->where('identity_mismatch', false)
            // E-mail de origem, quando a Even3 informou, precisa ser o mesmo da Person.
            ->where(fn ($q) => $q->whereNull('source_email')->orWhereRaw('lower(trim(source_email)) = ?', [$email]))
            ->whereHas('ticketType', fn ($q) => $q->where('internal_category', TicketCategory::Public->value))
            ->whereHas('event', fn ($q) => $q->whereIn('status', self::openEventStatuses()))
            ->exists();
    }

    /**
     * @return list<string>
     */
    private static function openEventStatuses(): array
    {
        return [EventStatus::Planned->value, EventStatus::Active->value];
    }
}
