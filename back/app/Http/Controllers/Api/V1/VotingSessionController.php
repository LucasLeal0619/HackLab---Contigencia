<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Voting\PublicVoterEnrollment;
use App\Domain\Voting\VotingAggregates;
use App\Domain\Voting\VotingSessionService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Voting\CancelVotingSessionRequest;
use App\Http\Requests\Voting\SyncCandidatesRequest;
use App\Http\Requests\Voting\SyncEligibilitiesRequest;
use App\Http\Requests\Voting\VotingSessionRequest;
use App\Http\Resources\VotingEligibilityResource;
use App\Http\Resources\VotingSessionResource;
use App\Models\Event;
use App\Models\PublicVote;
use App\Models\VotingEligibility;
use App\Models\VotingSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * Administração da votação pública. Nenhuma resposta traz eleitor → equipe; contagem por equipe só
 * em results(), com a sessão CLOSED.
 */
class VotingSessionController extends Controller
{
    private const COUNTS = ['teams', 'eligibilities', 'votes'];

    public function __construct(
        private readonly VotingSessionService $sessions,
        private readonly VotingAggregates $aggregates,
    ) {}

    /**
     * Filtros: status, search (nome).
     */
    public function index(Request $request, Event $event): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', VotingSession::class);

        $sessions = VotingSession::query()
            ->where('event_id', $event->id)
            ->withCount(self::COUNTS)
            ->when($request->query('status'), fn ($q, string $v) => $q->where('status', $v))
            ->when($request->query('search'), fn ($q, string $s) => $q->where('name', 'ilike', "%{$s}%"))
            ->orderByDesc('id')
            ->paginate(min((int) $request->query('per_page', 50), 100));

        return VotingSessionResource::collection($sessions);
    }

    public function store(VotingSessionRequest $request, Event $event): JsonResponse
    {
        $session = $this->sessions->create($event, $request->validated('name'), $request->user());

        return VotingSessionResource::make($session->loadCount(self::COUNTS))->response()->setStatusCode(201);
    }

    public function show(VotingSession $session): VotingSessionResource
    {
        Gate::authorize('view', $session);

        return VotingSessionResource::make($session->load('teams')->loadCount(self::COUNTS));
    }

    public function update(VotingSessionRequest $request, VotingSession $session): VotingSessionResource
    {
        return $this->detail($this->sessions->rename($session, $request->validated('name')));
    }

    public function syncTeams(SyncCandidatesRequest $request, VotingSession $session): VotingSessionResource
    {
        return $this->detail($this->sessions->syncTeams($session, $request->validated('team_ids')));
    }

    /**
     * Elegíveis com has_voted (nunca em quem votou). Filtros: search (nome/e-mail), has_voted.
     */
    public function eligibilities(Request $request, VotingSession $session): AnonymousResourceCollection
    {
        Gate::authorize('view', $session);

        $voted = PublicVote::query()->select('user_id')->where('voting_session_id', $session->id);

        $eligibilities = VotingEligibility::query()
            ->where('voting_eligibilities.voting_session_id', $session->id)
            ->join('users', 'users.id', '=', 'voting_eligibilities.user_id')
            ->join('people', 'people.id', '=', 'users.person_id')
            ->select('voting_eligibilities.*')
            ->selectRaw('exists (select 1 from public_votes pv where pv.voting_session_id = voting_eligibilities.voting_session_id and pv.user_id = voting_eligibilities.user_id) as has_voted')
            ->with('user.person', 'user.role')
            ->when($request->query('search'), fn ($q, string $s) => $q->where(fn ($w) => $w
                ->where('people.full_name', 'ilike', "%{$s}%")
                ->orWhere('users.email', 'ilike', "%{$s}%")))
            ->when($request->has('has_voted'), fn ($q) => $request->boolean('has_voted')
                ? $q->whereIn('voting_eligibilities.user_id', $voted)
                : $q->whereNotIn('voting_eligibilities.user_id', $voted))
            ->orderBy('people.full_name')
            ->paginate(min((int) $request->query('per_page', 50), 200));

        return VotingEligibilityResource::collection($eligibilities);
    }

    /**
     * Prontidão dos Votantes do público para a sessão (contagens por Person; sem dados pessoais).
     */
    public function publicVoterReadiness(VotingSession $session, PublicVoterEnrollment $enrollment): JsonResponse
    {
        Gate::authorize('view', $session);

        return response()->json(['data' => $enrollment->readiness($session)]);
    }

    /**
     * Adiciona à sessão (DRAFT) as contas Votante já ativadas pelos inscritos do público. Só adiciona.
     */
    public function addPublicVoters(Request $request, VotingSession $session, PublicVoterEnrollment $enrollment): JsonResponse
    {
        Gate::authorize('manageEligibility', $session);

        return response()->json(['data' => $enrollment->addActivatedPublicVoters($session, $request->user())]);
    }

    public function syncEligibilities(SyncEligibilitiesRequest $request, VotingSession $session): VotingSessionResource
    {
        return $this->detail($this->sessions->syncEligibilities($session, $request->validated('user_ids'), $request->user()));
    }

    public function open(VotingSession $session): VotingSessionResource
    {
        Gate::authorize('manage', $session);

        return $this->detail($this->sessions->open($session));
    }

    public function close(VotingSession $session): VotingSessionResource
    {
        Gate::authorize('manage', $session);

        return $this->detail($this->sessions->close($session));
    }

    public function cancel(CancelVotingSessionRequest $request, VotingSession $session): VotingSessionResource
    {
        return $this->detail($this->sessions->cancel($session, $request->validated('reason')));
    }

    /**
     * Participação: só totais, em qualquer status (inclusive OPEN). Sem contagem por equipe.
     */
    public function turnout(VotingSession $session): JsonResponse
    {
        Gate::authorize('viewAggregates', $session);

        return response()->json([
            'data' => ['voting_session_id' => $session->id, 'status' => $session->status->value] + $this->aggregates->turnout($session),
        ]);
    }

    /**
     * Contagem por equipe: só com a sessão CLOSED (422 em qualquer outro status, inclusive para o Administrador).
     */
    public function results(VotingSession $session): JsonResponse
    {
        Gate::authorize('viewAggregates', $session);

        return response()->json([
            'data' => $this->aggregates->results($session) + ['turnout' => $this->aggregates->turnout($session)],
            'meta' => ['note' => 'Escolha do Público desta sessão. Não é combinado com o resultado técnico.'],
        ]);
    }

    private function detail(VotingSession $session): VotingSessionResource
    {
        return VotingSessionResource::make($session->load('teams')->loadCount(self::COUNTS));
    }
}
