<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Participants\ParticipantService;
use App\Domain\Teams\TeamService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Participants\AssignTeamRequest;
use App\Http\Requests\Participants\StoreParticipantRequest;
use App\Http\Requests\Participants\UpdateParticipantRequest;
use App\Http\Resources\ParticipantResource;
use App\Models\Event;
use App\Models\Participant;
use App\Models\Team;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class ParticipantController extends Controller
{
    private const RELATIONS = ['person', 'schoolClass', 'activeMembership.team'];

    public function __construct(
        private readonly ParticipantService $participants,
        private readonly TeamService $teams,
    ) {}

    /**
     * Filtros: class_id, status, has_team (true/false), team_id, search (nome ou e-mail da pessoa).
     */
    public function index(Request $request, Event $event): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Participant::class);

        $participants = Participant::query()
            ->where('participants.event_id', $event->id)
            ->with(self::RELATIONS)
            ->when($request->query('class_id'), fn ($q, $classId) => $q->where('participants.class_id', $classId))
            ->when($request->query('status'), fn ($q, string $status) => $q->where('participants.status', $status))
            ->when($request->has('has_team'), fn ($q) => $request->boolean('has_team')
                ? $q->whereHas('activeMembership')
                : $q->whereDoesntHave('activeMembership'))
            ->when($request->query('team_id'), fn ($q, $teamId) => $q->whereHas('activeMembership', fn ($m) => $m->where('team_id', $teamId)))
            ->when($request->query('search'), fn ($q, string $search) => $q->whereHas('person', fn ($p) => $p
                ->whereILike('full_name', "%{$search}%")
                ->orWhereILike('email', "%{$search}%")))
            ->join('people', 'people.id', '=', 'participants.person_id')
            ->orderBy('people.full_name')
            ->select('participants.*')
            ->paginate(min((int) $request->query('per_page', 50), 100));

        return ParticipantResource::collection($participants);
    }

    public function store(StoreParticipantRequest $request, Event $event): ParticipantResource
    {
        return ParticipantResource::make($this->participants->create($event, $request->validated()));
    }

    public function show(Participant $participant): ParticipantResource
    {
        Gate::authorize('view', $participant);

        return ParticipantResource::make($participant->load([...self::RELATIONS, 'memberships.team']));
    }

    public function update(UpdateParticipantRequest $request, Participant $participant): ParticipantResource
    {
        return ParticipantResource::make($this->participants->update($participant, $request->validated()));
    }

    /**
     * Coloca o participante na equipe: adiciona se não tem equipe, move se tem outra (um único log).
     */
    public function updateTeam(AssignTeamRequest $request, Participant $participant): ParticipantResource
    {
        $this->teams->assign($participant, Team::query()->findOrFail($request->validated('team_id')));

        return ParticipantResource::make($participant->fresh(self::RELATIONS));
    }
}
