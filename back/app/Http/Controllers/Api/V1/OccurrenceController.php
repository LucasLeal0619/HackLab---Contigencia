<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Occurrences\OccurrenceService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Demands\CommentRequest;
use App\Http\Requests\Demands\ForwardRequest;
use App\Http\Requests\Demands\StatusActionRequest;
use App\Http\Requests\Occurrences\GenerateTaskRequest;
use App\Http\Requests\Occurrences\ResolveOccurrenceRequest;
use App\Http\Requests\Occurrences\StoreOccurrenceRequest;
use App\Http\Requests\Occurrences\UpdateOccurrenceRequest;
use App\Http\Resources\DemandInteractionResource;
use App\Http\Resources\OccurrenceResource;
use App\Http\Resources\TaskResource;
use App\Models\Event;
use App\Models\Occurrence;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class OccurrenceController extends Controller
{
    public function __construct(private readonly OccurrenceService $occurrences) {}

    /**
     * A visibilidade é aplicada na query (nunca busca global para esconder no front).
     */
    public function index(Request $request, Event $event): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Occurrence::class);

        $occurrences = Occurrence::query()
            ->where('event_id', $event->id)
            ->visibleTo($request->user())
            ->with(OccurrenceService::RELATIONS)
            ->when($request->query('search'), fn ($q, string $s) => $q->where(fn ($w) => $w->whereILike('title', "%{$s}%")->orWhereILike('reference', "%{$s}%")))
            ->when($request->query('status'), fn ($q, string $v) => $q->where('status', $v))
            ->when($request->query('priority'), fn ($q, string $v) => $q->where('priority', $v))
            ->when($request->query('category'), fn ($q, string $v) => $q->where('category', $v))
            ->when($request->query('origin_sector_id'), fn ($q, $v) => $q->where('origin_sector_id', $v))
            ->when($request->query('responsible_sector_id'), fn ($q, $v) => $q->where('responsible_sector_id', $v))
            ->when($request->query('involved_sector_id'), fn ($q, $v) => $q->whereHas('involvedSectors', fn ($s) => $s->where('sectors.id', $v)))
            ->when($request->query('assigned_user_id'), fn ($q, $v) => $q->where('assigned_user_id', $v))
            ->when($request->query('event_day_id'), fn ($q, $v) => $q->where('event_day_id', $v))
            ->when($request->query('team_id'), fn ($q, $v) => $q->where('team_id', $v))
            ->when($request->query('occurred_before'), fn ($q, $v) => $q->where('occurred_at', '<=', $v))
            ->when($request->query('occurred_after'), fn ($q, $v) => $q->where('occurred_at', '>=', $v))
            ->orderByDesc('id')
            ->paginate(min((int) $request->query('per_page', 50), 100));

        return OccurrenceResource::collection($occurrences);
    }

    public function store(StoreOccurrenceRequest $request, Event $event): OccurrenceResource
    {
        return OccurrenceResource::make($this->occurrences->create($event, $request->user(), $request->validated()));
    }

    public function show(Request $request, Occurrence $occurrence): OccurrenceResource
    {
        Gate::authorize('view', $occurrence);

        return OccurrenceResource::make($occurrence->load([...OccurrenceService::RELATIONS, 'interactions.user.person']))
            ->additional(['meta' => ['abilities' => $this->abilities($request, $occurrence)]]);
    }

    public function update(UpdateOccurrenceRequest $request, Occurrence $occurrence): OccurrenceResource
    {
        return OccurrenceResource::make($this->occurrences->update($occurrence, $request->validated(), $request->user()));
    }

    public function comment(CommentRequest $request, Occurrence $occurrence): JsonResponse
    {
        $interaction = $this->occurrences->comment($occurrence, $request->user(), $request->validated('message'));

        return DemandInteractionResource::make($interaction->load('user.person'))->response()->setStatusCode(201);
    }

    public function forward(ForwardRequest $request, Occurrence $occurrence): OccurrenceResource
    {
        return OccurrenceResource::make($this->occurrences->forward($occurrence, (int) $request->validated('sector_id'), $request->validated('reason'), $request->user()));
    }

    public function resolve(ResolveOccurrenceRequest $request, Occurrence $occurrence): OccurrenceResource
    {
        return OccurrenceResource::make($this->occurrences->resolve($occurrence, $request->user(), $request->validated('resolution')));
    }

    public function reopen(StatusActionRequest $request, Occurrence $occurrence): OccurrenceResource
    {
        return OccurrenceResource::make($this->occurrences->reopen($occurrence, $request->user(), $request->validated('message')));
    }

    public function generateTask(GenerateTaskRequest $request, Occurrence $occurrence): TaskResource
    {
        return TaskResource::make($this->occurrences->generateTask($occurrence, $request->user(), $request->validated()));
    }

    /**
     * @return array<string, bool>
     */
    private function abilities(Request $request, Occurrence $occurrence): array
    {
        $user = $request->user();

        return [
            'comment' => $user->can('comment', $occurrence),
            'change_status' => $user->can('changeStatus', $occurrence),
            'update_structure' => $user->can('updateStructure', $occurrence),
            'forward' => $user->can('forward', $occurrence),
            'generate_task' => $user->can('generateTask', $occurrence),
        ];
    }
}
