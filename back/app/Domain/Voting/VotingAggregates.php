<?php

namespace App\Domain\Voting;

use App\Domain\Results\Ranking;
use App\Models\PublicVote;
use App\Models\VotingEligibility;
use App\Models\VotingSession;
use App\Support\Math\Decimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Agregados da votação pública. Nunca devolvem eleitor → equipe.
 *
 * - participação (qualquer status): elegíveis, votos recebidos, percentual;
 * - contagem por equipe: só com a sessão CLOSED (nem o Administrador vê parcial durante OPEN).
 *
 * percentual do voto = 100 * votos da equipe / total de votos (não de elegíveis); participação usa elegíveis.
 */
class VotingAggregates
{
    /**
     * @return array{eligible_voters: int, votes_cast: int, pending_voters: int, turnout_percent: float}
     */
    public function turnout(VotingSession $session): array
    {
        $eligible = VotingEligibility::query()->where('voting_session_id', $session->id)->count();
        $votes = PublicVote::query()->where('voting_session_id', $session->id)->count();

        return [
            'eligible_voters' => $eligible,
            'votes_cast' => $votes,
            'pending_voters' => $eligible - $votes,
            'turnout_percent' => Decimal::round(Decimal::percent($votes, $eligible)),
        ];
    }

    /**
     * Resultado popular da sessão fechada: todas as candidatas (inclusive sem voto), com posição e empate.
     *
     * @return array{teams: list<array<string, mixed>>, total_votes: int, has_ties: bool}
     */
    public function results(VotingSession $session): array
    {
        if (! $session->isClosed()) {
            throw ValidationException::withMessages([
                'session' => "Contagem por equipe só existe com a votação encerrada (status: {$session->status->value}).",
            ]);
        }

        $counts = PublicVote::query()->where('voting_session_id', $session->id)
            ->groupBy('team_id')->selectRaw('team_id, count(*) as votes')->pluck('votes', 'team_id');
        $total = (int) $counts->sum();

        $rows = DB::table('voting_session_teams as c')
            ->join('teams', 'teams.id', '=', 'c.team_id')
            ->where('c.voting_session_id', $session->id)
            ->select('teams.id', 'teams.name')
            ->get()
            ->map(function ($team) use ($counts, $total) {
                $votes = (int) ($counts[$team->id] ?? 0);

                return [
                    'team' => ['id' => (int) $team->id, 'name' => $team->name],
                    'vote_count' => $votes,
                    'percentage' => Decimal::round(Decimal::percent($votes, $total)),
                ];
            })
            ->values()
            ->all();

        $ranked = Ranking::competition(
            $rows,
            fn (array $row) => $row['vote_count'],
            fn (int $a, int $b) => $a <=> $b,
            fn (array $row) => $row['team']['name'],
        );

        return ['teams' => $ranked, 'total_votes' => $total, 'has_ties' => Ranking::hasTies($ranked)];
    }
}
