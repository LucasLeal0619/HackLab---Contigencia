<?php

namespace App\Domain\Voting;

use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditLogger;
use App\Domain\Integrations\Enums\RegistrationStatus;
use App\Domain\Integrations\Enums\TicketCategory;
use App\Domain\Users\Enums\RoleCode;
use App\Domain\Users\Enums\UserStatus;
use App\Models\EventRegistration;
use App\Models\Person;
use App\Models\User;
use App\Models\VotingEligibility;
use App\Models\VotingSession;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Preparação dos Votantes do público para uma sessão (Complemento 11.1).
 *
 * O primeiro acesso cria a conta VOTER mas nunca a elegibilidade (autenticação e votação desacopladas).
 * Antes de abrir a sessão, o Administrador adiciona em lote as contas VOTER ACTIVE cuja Person tem
 * inscrição CONFIRMED, sem identity_mismatch, em entrada mapeada como PUBLIC no evento da sessão.
 * Só adiciona (elegíveis manuais ficam), é idempotente e respeita a trava da Fase 7 (só DRAFT).
 * Tudo é contado por Person: várias inscrições da mesma pessoa contam uma vez.
 */
class PublicVoterEnrollment
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @return array{public_confirmed_people: int, activated_voter_accounts: int, already_eligible: int, public_without_account: int, public_with_other_account: int, eligible_voters: int, candidates: int}
     */
    public function readiness(VotingSession $session): array
    {
        $people = $this->publicPeople($session->event_id);
        $voters = $this->publicVoters($session->event_id);

        return [
            'public_confirmed_people' => (int) DB::query()->fromSub($people, 'p')->count(),
            'activated_voter_accounts' => (clone $voters)->count(),
            'already_eligible' => (clone $voters)->whereIn('users.id', $this->eligibleQuery($session))->count(),
            'public_without_account' => Person::query()->whereIn('id', $people)->whereDoesntHave('user')->count(),
            // Têm conta, mas não ativa ou com outro perfil: não entram (conta existente nunca muda de perfil).
            'public_with_other_account' => Person::query()->whereIn('id', $people)->whereHas('user')
                ->whereNotIn('id', (clone $voters)->select('users.person_id'))->count(),
            'eligible_voters' => VotingEligibility::query()->where('voting_session_id', $session->id)->count(),
            'candidates' => (int) DB::table('voting_session_teams')->where('voting_session_id', $session->id)->count(),
        ];
    }

    /**
     * Adiciona à sessão (DRAFT) os Votantes do público já ativados que ainda não são elegíveis.
     *
     * @return array{eligible_public_accounts: int, already_eligible: int, added: int, public_without_account: int}
     */
    public function addActivatedPublicVoters(VotingSession $session, User $actor): array
    {
        return DB::transaction(function () use ($session, $actor) {
            // Lock da sessão: dois pedidos simultâneos são serializados e o segundo encontra tudo já adicionado.
            $session = VotingSession::query()->lockForUpdate()->findOrFail($session->id);

            if (! $session->isDraft()) {
                throw ValidationException::withMessages(['session' => "Sessão {$session->status->value}: os elegíveis ficam travados depois da abertura."]);
            }

            $candidates = $this->publicVoters($session->event_id)->sharedLock()->orderBy('users.id')->pluck('users.id')->map(fn ($id) => (int) $id)->all();
            $current = VotingEligibility::query()->where('voting_session_id', $session->id)->pluck('user_id')->map(fn ($id) => (int) $id)->all();
            $toAdd = array_values(array_diff($candidates, $current));

            if ($toAdd !== []) {
                $now = now();
                VotingEligibility::query()->insert(array_map(fn (int $userId) => [
                    'event_id' => $session->event_id,
                    'voting_session_id' => $session->id,
                    'user_id' => $userId,
                    'created_by_user_id' => $actor->id,
                    'created_at' => $now,
                ], $toAdd));

                $this->audit->record(
                    AuditAction::VOTING_ELIGIBILITY_CHANGED,
                    'voting',
                    'Votantes do público já ativados adicionados à sessão "'.$session->name.'" ('.count($toAdd).' novos; '.(count($current) + count($toAdd)).' no total).',
                    entity: $session,
                    before: ['eligible_count' => count($current)],
                    after: ['eligible_count' => count($current) + count($toAdd), 'added' => $toAdd, 'source' => 'PUBLIC_ACTIVATED_VOTERS'],
                );
            }

            $people = $this->publicPeople($session->event_id);

            return [
                'eligible_public_accounts' => count($candidates),
                'already_eligible' => count($candidates) - count($toAdd),
                'added' => count($toAdd),
                'public_without_account' => Person::query()->whereIn('id', $people)->whereDoesntHave('user')->count(),
            ];
        });
    }

    /**
     * Persons com inscrição confirmada, sem conflito de identidade, em entrada mapeada como PUBLIC no evento.
     */
    private function publicPeople(int $eventId): Builder
    {
        return EventRegistration::query()
            ->select('person_id')
            ->distinct()
            ->where('event_id', $eventId)
            ->where('status', RegistrationStatus::Confirmed->value)
            ->where('identity_mismatch', false)
            ->whereHas('ticketType', fn ($q) => $q->where('internal_category', TicketCategory::Public->value));
    }

    /**
     * Contas VOTER ACTIVE dessas Persons (no máximo uma por Person: users.person_id é único).
     */
    private function publicVoters(int $eventId): Builder
    {
        return User::query()
            ->whereIn('users.person_id', $this->publicPeople($eventId))
            ->where('users.status', UserStatus::Active->value)
            ->whereHas('role', fn ($q) => $q->where('code', RoleCode::Voter->value));
    }

    private function eligibleQuery(VotingSession $session): Builder
    {
        return VotingEligibility::query()->select('user_id')->where('voting_session_id', $session->id);
    }
}
