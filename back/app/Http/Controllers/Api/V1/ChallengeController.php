<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Challenges\ChallengeService;
use App\Domain\Challenges\Enums\ChallengeStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Challenges\AssignChallengeTeamRequest;
use App\Http\Requests\Challenges\ChallengeRequest;
use App\Http\Requests\Challenges\ChangeChallengeStatusRequest;
use App\Http\Resources\ChallengeResource;
use App\Models\Challenge;
use App\Models\Event;
use App\Models\Team;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class ChallengeController extends Controller
{
    public function __construct(private readonly ChallengeService $challenges) {}

    /**
     * Filtros: status, company_id, has_company, has_team, search (título).
     */
    public function index(Request $request, Event $event): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Challenge::class);

        $challenges = Challenge::query()
            ->where('event_id', $event->id)
            ->with('company', 'team')
            ->when($request->query('status'), fn ($q, string $status) => $q->where('status', $status))
            ->when($request->query('company_id'), fn ($q, $companyId) => $q->where('company_id', $companyId))
            ->when($request->has('has_company'), fn ($q) => $request->boolean('has_company')
                ? $q->whereNotNull('company_id')
                : $q->whereNull('company_id'))
            ->when($request->has('has_team'), fn ($q) => $request->boolean('has_team')
                ? $q->whereHas('team')
                : $q->whereDoesntHave('team'))
            ->when($request->query('search'), fn ($q, string $search) => $q->whereILike('title', "%{$search}%"))
            ->orderBy('title')
            ->paginate(min((int) $request->query('per_page', 50), 100));

        return ChallengeResource::collection($challenges);
    }

    public function store(ChallengeRequest $request, Event $event): ChallengeResource
    {
        return ChallengeResource::make($this->challenges->create($event, $request->validated()));
    }

    /**
     * Desafio com empresa e equipe, quando existirem.
     */
    public function show(Challenge $challenge): ChallengeResource
    {
        Gate::authorize('view', $challenge);

        return ChallengeResource::make($challenge->load('company', 'team'));
    }

    public function update(ChallengeRequest $request, Challenge $challenge): ChallengeResource
    {
        return ChallengeResource::make($this->challenges->update($challenge, $request->validated()));
    }

    public function updateStatus(ChangeChallengeStatusRequest $request, Challenge $challenge): ChallengeResource
    {
        return ChallengeResource::make($this->challenges->changeStatus($challenge, ChallengeStatus::from($request->validated('status'))));
    }

    /**
     * Distribui, move ou retira (team_id null) a equipe do desafio.
     */
    public function updateTeam(AssignChallengeTeamRequest $request, Challenge $challenge): ChallengeResource
    {
        $teamId = $request->validated('team_id');

        return ChallengeResource::make($this->challenges->assignTeam(
            $challenge,
            $teamId === null ? null : Team::query()->findOrFail($teamId),
        ));
    }
}
