<?php

namespace Tests\Feature\Audit;

use App\Domain\Audit\AuditAction;
use App\Models\AuditLog;
use App\Models\Occurrence;
use App\Models\Task;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\DemandScenario;
use Tests\DatabaseTestCase;

/**
 * Pendências e ocorrências: comentário = só interação; encaminhar, concluir/resolver e reabrir = interação
 * + exatamente um log de auditoria (histórico contextual ≠ auditoria).
 */
class AuditDemandCoverageTest extends DatabaseTestCase
{
    use DemandScenario;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildDemandScenario();
    }

    /**
     * Executa a operação e devolve [ações de auditoria novas, quantidade de interações novas].
     *
     * @return array{0: list<string>, 1: int}
     */
    private function effects(callable $operation, string $interactionsTable): array
    {
        $lastLog = (int) AuditLog::query()->max('id');
        $interactions = DB::table($interactionsTable)->count();

        $operation();

        return [
            AuditLog::query()->where('id', '>', $lastLog)->orderBy('id')->pluck('action')->all(),
            DB::table($interactionsTable)->count() - $interactions,
        ];
    }

    public function test_task_comment_forward_complete_and_reopen(): void
    {
        $id = $this->actingAs($this->adminUser)->postJson("/api/v1/events/{$this->event->id}/tasks", [
            'title' => 'Reservar equipamentos', 'description' => 'x', 'origin_sector_id' => $this->sectorA->id, 'responsible_sector_id' => $this->sectorA->id,
        ])->assertCreated()->json('data.id');
        $base = "/api/v1/tasks/{$id}";
        $admin = $this->actingAs($this->adminUser);

        $this->assertSame([[], 1], $this->effects(fn () => $admin->postJson("{$base}/comments", ['message' => 'Acompanhando'])->assertCreated(), 'task_interactions'));
        $this->assertSame([[AuditAction::TASK_FORWARDED], 1], $this->effects(fn () => $admin->postJson("{$base}/forward", ['sector_id' => $this->sectorB->id, 'reason' => 'Com B'])->assertOk(), 'task_interactions'));
        $this->assertSame([[AuditAction::TASK_COMPLETED], 1], $this->effects(fn () => $admin->postJson("{$base}/complete")->assertOk(), 'task_interactions'));
        $this->assertSame([[AuditAction::TASK_REOPENED], 1], $this->effects(fn () => $admin->postJson("{$base}/reopen", ['reason' => 'Faltou'])->assertOk(), 'task_interactions'));

        $forward = AuditLog::query()->where('action', AuditAction::TASK_FORWARDED)->sole();
        $this->assertSame($this->event->id, $forward->event_id);
        $this->assertSame(Task::query()->find($id)->reference.' · Reservar equipamentos', $forward->entity_label);
    }

    public function test_occurrence_comment_resolve_and_reopen(): void
    {
        $id = $this->actingAs($this->adminUser)->postJson("/api/v1/events/{$this->event->id}/occurrences", [
            'title' => 'Queda de energia', 'description' => 'x', 'category' => 'INFRASTRUCTURE',
            'origin_sector_id' => $this->sectorA->id, 'responsible_sector_id' => $this->sectorA->id,
        ])->assertCreated()->json('data.id');
        $base = "/api/v1/occurrences/{$id}";
        $admin = $this->actingAs($this->adminUser);

        $this->assertSame([[], 1], $this->effects(fn () => $admin->postJson("{$base}/comments", ['message' => 'Verificando'])->assertCreated(), 'occurrence_interactions'));
        $this->assertSame([[AuditAction::OCCURRENCE_FORWARDED], 1], $this->effects(fn () => $admin->postJson("{$base}/forward", ['sector_id' => $this->sectorB->id, 'reason' => 'Com B'])->assertOk(), 'occurrence_interactions'));
        $this->assertSame([[AuditAction::OCCURRENCE_RESOLVED], 1], $this->effects(fn () => $admin->postJson("{$base}/resolve", ['resolution' => 'Disjuntor religado'])->assertOk(), 'occurrence_interactions'));
        $this->assertSame([[AuditAction::OCCURRENCE_REOPENED], 1], $this->effects(fn () => $admin->postJson("{$base}/reopen", ['reason' => 'Caiu de novo'])->assertOk(), 'occurrence_interactions'));

        $this->assertSame(Occurrence::query()->find($id)->reference.' · Queda de energia', AuditLog::query()->where('action', AuditAction::OCCURRENCE_RESOLVED)->sole()->entity_label);
    }
}
