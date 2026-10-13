<?php

namespace App\Domain\Events;

use App\Domain\Demands\Enums\DemandPriority;
use App\Domain\Occurrences\Enums\OccurrenceStatus;
use App\Domain\Participants\Enums\ParticipantStatus;
use App\Domain\Reports\OverdueTasks;
use App\Domain\Tasks\Enums\TaskStatus;
use App\Models\Challenge;
use App\Models\Company;
use App\Models\Event;
use App\Models\Occurrence;
use App\Models\Participant;
use App\Models\Task;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

/**
 * Resumo do Dashboard numa resposta só: contagens (nunca registros) dos módulos que o usuário pode listar.
 *
 * Cada bloco segue a mesma Policy (viewAny) e o mesmo recorte da listagem de destino — pendências e
 * ocorrências com visibleTo (setor do usuário) —, então o número bate com o que a página mostra.
 * Bloco sem permissão vem null. Somente leitura: não audita.
 */
class EventDashboard
{
    public function __construct(private readonly OverdueTasks $overdue) {}

    /**
     * @return array<string, mixed>
     */
    public function build(Event $event, User $user): array
    {
        return [
            'event_id' => $event->id,
            'participants' => $this->when($user, Participant::class, fn () => $this->participants($event)),
            'teams' => $this->when($user, Team::class, fn () => ['total' => Team::query()->where('event_id', $event->id)->count()]),
            'companies' => $this->when($user, Company::class, fn () => ['total' => Company::query()->where('event_id', $event->id)->count()]),
            'challenges' => $this->when($user, Challenge::class, fn () => ['total' => Challenge::query()->where('event_id', $event->id)->count()]),
            'tasks' => $this->when($user, Task::class, fn () => $this->tasks($event, $user)),
            'occurrences' => $this->when($user, Occurrence::class, fn () => $this->occurrences($event, $user)),
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * @param  class-string  $model
     */
    private function when(User $user, string $model, callable $counts): ?array
    {
        return Gate::forUser($user)->allows('viewAny', $model) ? $counts() : null;
    }

    /**
     * @return array{total: int, without_team: int}
     */
    private function participants(Event $event): array
    {
        $base = Participant::query()->where('participants.event_id', $event->id);

        return [
            'total' => (clone $base)->count(),
            // Mesmo filtro do atalho "Sem equipe" (has_team=false&status=AVAILABLE).
            'without_team' => (clone $base)->where('participants.status', ParticipantStatus::Available->value)
                ->whereDoesntHave('activeMembership')->count(),
        ];
    }

    /**
     * @return array{pending: int, in_progress: int, overdue: int}
     */
    private function tasks(Event $event, User $user): array
    {
        $visible = fn (): Builder => Task::query()->where('event_id', $event->id)->visibleTo($user);
        $byStatus = $this->countByStatus($visible());

        return [
            'pending' => $byStatus[TaskStatus::Pending->value] ?? 0,
            'in_progress' => $byStatus[TaskStatus::InProgress->value] ?? 0,
            'overdue' => $this->overdue->apply($visible())->count(),
        ];
    }

    /**
     * @return array{open: int, in_progress: int, urgent_open: int}
     */
    private function occurrences(Event $event, User $user): array
    {
        $visible = fn (): Builder => Occurrence::query()->where('event_id', $event->id)->visibleTo($user);
        $byStatus = $this->countByStatus($visible());

        return [
            'open' => $byStatus[OccurrenceStatus::Open->value] ?? 0,
            'in_progress' => $byStatus[OccurrenceStatus::InProgress->value] ?? 0,
            'urgent_open' => $visible()->where('status', OccurrenceStatus::Open->value)
                ->where('priority', DemandPriority::Urgent->value)->count(),
        ];
    }

    /**
     * Uma consulta agrupada por status (em vez de uma contagem por status).
     *
     * @return array<string, int>
     */
    private function countByStatus(Builder $query): array
    {
        return $query->toBase()->reorder()->groupBy('status')->selectRaw('status, count(*) as total')
            ->pluck('total', 'status')->map(fn ($total) => (int) $total)->all();
    }
}
