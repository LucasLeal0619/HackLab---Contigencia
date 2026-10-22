<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Jurors\Enums\JurorStatus;
use App\Domain\Jurors\JurorService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Jurors\ChangeJurorStatusRequest;
use App\Http\Requests\Jurors\StoreJurorRequest;
use App\Http\Requests\Jurors\SyncAssignmentsRequest;
use App\Http\Requests\Jurors\UpdateJurorRequest;
use App\Http\Resources\JurorAssignmentResource;
use App\Http\Resources\JurorResource;
use App\Models\Event;
use App\Models\Juror;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class JurorController extends Controller
{
    private const RELATIONS = ['person', 'company', 'account.role.permissions'];

    public function __construct(private readonly JurorService $jurors) {}

    /**
     * Filtros: status, company_id, search (nome da pessoa).
     */
    public function index(Request $request, Event $event): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Juror::class);

        $jurors = Juror::query()
            ->where('jurors.event_id', $event->id)
            ->with(self::RELATIONS)
            ->withCount('activeAssignments')
            ->when($request->query('status'), fn ($q, string $v) => $q->where('jurors.status', $v))
            ->when($request->query('company_id'), fn ($q, $v) => $q->where('company_id', $v))
            ->when($request->query('search'), fn ($q, string $s) => $q->whereHas('person', fn ($p) => $p->whereILike('full_name', "%{$s}%")))
            ->join('people', 'people.id', '=', 'jurors.person_id')
            ->orderBy('people.full_name')
            ->select('jurors.*')
            ->paginate(min((int) $request->query('per_page', 50), 100));

        return JurorResource::collection($jurors);
    }

    public function store(StoreJurorRequest $request, Event $event): JurorResource
    {
        return JurorResource::make($this->jurors->create($event, $request->validated())->load(self::RELATIONS));
    }

    public function show(Juror $juror): JurorResource
    {
        Gate::authorize('view', $juror);

        return JurorResource::make($juror->load([...self::RELATIONS, 'assignments.team'])->loadCount('activeAssignments'));
    }

    public function update(UpdateJurorRequest $request, Juror $juror): JurorResource
    {
        return JurorResource::make($this->jurors->update($juror, $request->validated())->load(self::RELATIONS));
    }

    public function updateStatus(ChangeJurorStatusRequest $request, Juror $juror): JurorResource
    {
        return JurorResource::make($this->jurors->changeStatus($juror, JurorStatus::from($request->validated('status')))->load(self::RELATIONS));
    }

    /**
     * Atribuições (ativas e revogadas, para histórico).
     */
    public function assignments(Juror $juror): AnonymousResourceCollection
    {
        Gate::authorize('view', $juror);

        return JurorAssignmentResource::collection($juror->assignments()->with('team')->orderBy('team_id')->get());
    }

    public function syncAssignments(SyncAssignmentsRequest $request, Juror $juror): AnonymousResourceCollection
    {
        return JurorAssignmentResource::collection($this->jurors->syncAssignments($juror, $request->validated('team_ids'), $request->user()));
    }
}
