<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Tasks\Enums\TaskStatus;
use App\Domain\Tasks\TaskService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Demands\CommentRequest;
use App\Http\Requests\Demands\ForwardRequest;
use App\Http\Requests\Demands\StatusActionRequest;
use App\Http\Requests\Tasks\StoreTaskRequest;
use App\Http\Requests\Tasks\UpdateTaskRequest;
use App\Http\Resources\DemandInteractionResource;
use App\Http\Resources\TaskResource;
use App\Models\Event;
use App\Models\Task;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class TaskController extends Controller
{
    public function __construct(private readonly TaskService $tasks) {}

    /**
     * A visibilidade é aplicada na query (nunca busca global para esconder no front).
     */
    public function index(Request $request, Event $event): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Task::class);

        $tasks = Task::query()
            ->where('event_id', $event->id)
            ->visibleTo($request->user())
            ->with(TaskService::RELATIONS)
            ->when($request->query('search'), fn ($q, string $s) => $q->where(fn ($w) => $w->whereILike('title', "%{$s}%")->orWhereILike('reference', "%{$s}%")))
            ->when($request->query('status'), fn ($q, string $v) => $q->where('status', $v))
            ->when($request->query('priority'), fn ($q, string $v) => $q->where('priority', $v))
            ->when($request->query('origin_sector_id'), fn ($q, $v) => $q->where('origin_sector_id', $v))
            ->when($request->query('responsible_sector_id'), fn ($q, $v) => $q->where('responsible_sector_id', $v))
            ->when($request->query('involved_sector_id'), fn ($q, $v) => $q->whereHas('involvedSectors', fn ($s) => $s->where('sectors.id', $v)))
            ->when($request->query('assigned_user_id'), fn ($q, $v) => $q->where('assigned_user_id', $v))
            ->when($request->query('due_before'), fn ($q, $v) => $q->where('due_at', '<=', $v))
            ->when($request->query('due_after'), fn ($q, $v) => $q->where('due_at', '>=', $v))
            ->when($request->boolean('overdue'), fn ($q) => $q->where('status', '!=', TaskStatus::Completed->value)->where('due_at', '<', now()))
            ->when($request->query('source_occurrence_id'), fn ($q, $v) => $q->where('source_occurrence_id', $v))
            ->orderByDesc('id')
            ->paginate(min((int) $request->query('per_page', 50), 100));

        return TaskResource::collection($tasks);
    }

    public function store(StoreTaskRequest $request, Event $event): TaskResource
    {
        return TaskResource::make($this->tasks->create($event, $request->user(), $request->validated()));
    }

    public function show(Request $request, Task $task): TaskResource
    {
        Gate::authorize('view', $task);

        return TaskResource::make($task->load([...TaskService::RELATIONS, 'interactions.user.person']))
            ->additional(['meta' => ['abilities' => $this->abilities($request, $task)]]);
    }

    public function update(UpdateTaskRequest $request, Task $task): TaskResource
    {
        return TaskResource::make($this->tasks->update($task, $request->validated(), $request->user()));
    }

    public function comment(CommentRequest $request, Task $task): JsonResponse
    {
        $interaction = $this->tasks->comment($task, $request->user(), $request->validated('message'));

        return DemandInteractionResource::make($interaction->load('user.person'))->response()->setStatusCode(201);
    }

    public function forward(ForwardRequest $request, Task $task): TaskResource
    {
        return TaskResource::make($this->tasks->forward($task, (int) $request->validated('sector_id'), $request->validated('reason'), $request->user()));
    }

    public function complete(StatusActionRequest $request, Task $task): TaskResource
    {
        return TaskResource::make($this->tasks->complete($task, $request->user(), $request->validated('message')));
    }

    public function reopen(StatusActionRequest $request, Task $task): TaskResource
    {
        return TaskResource::make($this->tasks->reopen($task, $request->user(), $request->validated('message')));
    }

    /**
     * O que o usuário pode fazer nesta pendência (para o front montar a tela; a API sempre revalida).
     *
     * @return array<string, bool>
     */
    private function abilities(Request $request, Task $task): array
    {
        $user = $request->user();

        return [
            'comment' => $user->can('comment', $task),
            'change_status' => $user->can('changeStatus', $task),
            'update_structure' => $user->can('updateStructure', $task),
            'forward' => $user->can('forward', $task),
        ];
    }
}
