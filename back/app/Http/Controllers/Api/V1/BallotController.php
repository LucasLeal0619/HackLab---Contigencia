<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Voting\Enums\VotingSessionStatus;
use App\Domain\Voting\PublicVoteService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Voting\CastVoteRequest;
use App\Models\Event;
use App\Models\PublicVote;
use App\Models\Team;
use App\Models\VotingSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Área do eleitor (User VOTER elegível). Nunca devolve contagem: só a sessão, as candidatas,
 * se o próprio usuário já votou e o próprio voto.
 */
class BallotController extends Controller
{
    public function __construct(private readonly PublicVoteService $votes) {}

    /**
     * Sessões em que o usuário é elegível (DRAFT, OPEN e CLOSED; canceladas não aparecem).
     */
    public function mySessions(Request $request, Event $event): JsonResponse
    {
        Gate::authorize('participateAny', VotingSession::class);

        $userId = $request->user()->id;

        $sessions = VotingSession::query()
            ->where('event_id', $event->id)
            ->whereIn('status', [VotingSessionStatus::Draft->value, VotingSessionStatus::Open->value, VotingSessionStatus::Closed->value])
            ->whereHas('eligibilities', fn ($q) => $q->where('user_id', $userId))
            ->withExists(['votes as has_voted' => fn ($q) => $q->where('user_id', $userId)])
            ->orderByDesc('id')
            ->get()
            ->map(fn (VotingSession $s) => [
                'id' => $s->id,
                'name' => $s->name,
                'status' => $s->status->value,
                'opened_at' => $s->opened_at?->toIso8601String(),
                'closed_at' => $s->closed_at?->toIso8601String(),
                'has_voted' => (bool) $s->has_voted,
            ])
            ->values();

        return response()->json(['data' => $sessions]);
    }

    /**
     * Cédula: candidatas com desafio/empresa resumidos (relações existentes). Em DRAFT a lista ainda pode mudar
     * e não é devolvida.
     */
    public function ballot(Request $request, VotingSession $session): JsonResponse
    {
        Gate::authorize('vote', $session);

        $candidates = $session->isDraft() ? collect() : $session->teams()->with('challenge.company')->orderBy('name')->get()
            ->map(fn (Team $team) => [
                'team' => ['id' => $team->id, 'name' => $team->name],
                'challenge' => $team->challenge === null ? null : ['id' => $team->challenge->id, 'title' => $team->challenge->title],
                'company' => $team->challenge?->company === null ? null : ['id' => $team->challenge->company->id, 'name' => $team->challenge->company->name],
            ])
            ->values();

        return response()->json([
            'data' => [
                'session' => ['id' => $session->id, 'name' => $session->name, 'status' => $session->status->value, 'opened_at' => $session->opened_at?->toIso8601String(), 'closed_at' => $session->closed_at?->toIso8601String()],
                'can_vote' => $session->isOpen() && ! $this->ownVoteQuery($session, $request)->exists(),
                'has_voted' => $this->ownVoteQuery($session, $request)->exists(),
                'candidates' => $candidates,
            ],
        ]);
    }

    /**
     * 201 no primeiro voto; 200 com o mesmo voto num retry idêntico; 422 ao tentar outra equipe.
     */
    public function vote(CastVoteRequest $request, VotingSession $session): JsonResponse
    {
        [$vote, $created] = $this->votes->cast($session, $request->user(), (int) $request->validated('team_id'));

        return response()->json(['data' => $this->ownVote($vote)], $created ? 201 : 200);
    }

    /**
     * Confirmação do próprio voto (só o próprio eleitor vê a própria escolha).
     */
    public function myVote(Request $request, VotingSession $session): JsonResponse
    {
        Gate::authorize('vote', $session);

        $vote = $this->ownVoteQuery($session, $request)->first();

        return response()->json(['data' => $vote === null ? null : $this->ownVote($vote)]);
    }

    private function ownVoteQuery(VotingSession $session, Request $request)
    {
        return PublicVote::query()->where('voting_session_id', $session->id)->where('user_id', $request->user()->id);
    }

    /**
     * @return array<string, mixed>
     */
    private function ownVote(PublicVote $vote): array
    {
        $team = Team::query()->find($vote->team_id);

        return [
            'voting_session_id' => $vote->voting_session_id,
            'team' => ['id' => $team->id, 'name' => $team->name],
            'cast_at' => $vote->cast_at?->toIso8601String(),
            'final' => true,
        ];
    }
}
