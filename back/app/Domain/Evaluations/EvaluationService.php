<?php

namespace App\Domain\Evaluations;

use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditLogger;
use App\Domain\Evaluations\Enums\EvaluationStatus;
use App\Domain\Results\ResultPublicationService;
use App\Models\Evaluation;
use App\Models\EvaluationCriterion;
use App\Models\EvaluationScore;
use App\Models\JurorTeamAssignment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Avaliação pelo jurado (rascunho, envio, reenvio) e revisão solicitada pelo Administrador.
 *
 * O Administrador nunca altera notas: ele devolve a avaliação (REVISION_REQUESTED) e o próprio
 * jurado corrige e reenvia. Rascunho não gera auditoria; envio e revisão geram um log cada,
 * sem notas nem comentários.
 */
class EvaluationService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Salva parcialmente. Cria a avaliação na primeira gravação.
     *
     * @param  array{comments?: ?string, scores?: list<array{criterion_id: int, score: int|float|string|null, comment?: ?string}>}  $data
     */
    public function saveDraft(JurorTeamAssignment $assignment, User $actor, array $data): Evaluation
    {
        return DB::transaction(function () use ($assignment, $data) {
            $evaluation = $this->writableEvaluation($assignment);
            $this->apply($evaluation, $data);

            return $evaluation->load('scores.criterion');
        });
    }

    /**
     * Grava as notas finais e envia, na mesma transação. Exige nota em todos os critérios ativos.
     *
     * @param  array{comments?: ?string, scores?: list<array<string, mixed>>}  $data
     */
    public function submit(JurorTeamAssignment $assignment, User $actor, array $data): Evaluation
    {
        return DB::transaction(function () use ($assignment, $data) {
            $evaluation = $this->writableEvaluation($assignment);
            $this->apply($evaluation, $data);

            $active = EvaluationCriterion::query()->where('event_id', $evaluation->event_id)->where('active', true)->pluck('name', 'id');
            $scored = $evaluation->scores()->pluck('criterion_id')->map(fn ($id) => (int) $id)->all();
            $missing = $active->except($scored);

            if ($missing->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'scores' => 'Preencha todos os critérios antes de enviar. Faltam: '.$missing->implode(', ').'.',
                ]);
            }

            $previous = $evaluation->status;
            $evaluation->forceFill(['status' => EvaluationStatus::Submitted, 'submitted_at' => now()])->save();

            $resubmission = $previous === EvaluationStatus::RevisionRequested;

            $this->audit->record(
                $resubmission ? AuditAction::EVALUATION_RESUBMITTED : AuditAction::EVALUATION_SUBMITTED,
                'evaluations',
                ($resubmission ? 'Avaliação reenviada' : 'Avaliação enviada')." pelo jurado {$assignment->juror_id} para a equipe {$assignment->team_id}.",
                entity: $evaluation,
                before: ['status' => $previous->value],
                after: $this->transitionSnapshot($evaluation),
            );

            return $evaluation->load('scores.criterion');
        });
    }

    /**
     * Administrador devolve uma avaliação enviada para o jurado corrigir. Motivo obrigatório.
     */
    public function requestRevision(Evaluation $evaluation, User $actor, string $reason): Evaluation
    {
        return DB::transaction(function () use ($evaluation, $actor, $reason) {
            // Evento antes da avaliação (mesma ordem do jurado ao escrever): serializa com a publicação.
            DB::table('events')->where('id', $evaluation->event_id)->lockForUpdate()->first();
            ResultPublicationService::ensureNotPublished($evaluation->event_id, 'status', 'solicitar revisão de avaliação');

            $evaluation = Evaluation::query()->lockForUpdate()->findOrFail($evaluation->id);

            if (! $evaluation->isSubmitted()) {
                throw ValidationException::withMessages(['status' => 'Só avaliações enviadas podem voltar para revisão.']);
            }

            if (! $evaluation->assignment()?->isActive()) {
                throw ValidationException::withMessages(['status' => 'A atribuição está revogada: o jurado não poderia corrigir.']);
            }

            $before = $this->transitionSnapshot($evaluation) + ['previous_revision_reason' => $evaluation->revision_reason];

            $evaluation->forceFill([
                'status' => EvaluationStatus::RevisionRequested,
                'revision_reason' => $reason,
                'revision_requested_at' => now(),
                'revision_requested_by_user_id' => $actor->id,
            ])->save();

            $this->audit->record(
                AuditAction::EVALUATION_REVISION_REQUESTED,
                'evaluations',
                "Revisão solicitada para a avaliação {$evaluation->id} (jurado {$evaluation->juror_id}, equipe {$evaluation->team_id}).",
                entity: $evaluation,
                before: $before,
                after: $this->transitionSnapshot($evaluation) + ['reason' => $reason],
            );

            return $evaluation->load('scores.criterion');
        });
    }

    /**
     * Relê atribuição e avaliação com lock. Só escreve com atribuição e jurado ativos e avaliação não enviada.
     */
    private function writableEvaluation(JurorTeamAssignment $assignment): Evaluation
    {
        $assignment = JurorTeamAssignment::query()->with('juror')->lockForUpdate()->findOrFail($assignment->id);

        if (! $assignment->isActive()) {
            throw ValidationException::withMessages(['assignment' => 'Atribuição revogada: a avaliação não pode mais ser alterada.']);
        }

        if (! $assignment->juror->isActive()) {
            throw ValidationException::withMessages(['assignment' => 'Jurado inativo não altera avaliações.']);
        }

        // Serializa com alterações de critérios (o primeiro rascunho trava a estrutura do evento).
        DB::table('events')->where('id', $assignment->event_id)->lockForUpdate()->first();
        ResultPublicationService::ensureNotPublished($assignment->event_id, 'status', 'alterar avaliações');

        $evaluation = Evaluation::query()
            ->where('juror_id', $assignment->juror_id)
            ->where('team_id', $assignment->team_id)
            ->lockForUpdate()
            ->first()
            ?? Evaluation::query()->create([
                'event_id' => $assignment->event_id,
                'juror_id' => $assignment->juror_id,
                'team_id' => $assignment->team_id,
                'status' => EvaluationStatus::Draft->value,
            ])->refresh();

        if ($evaluation->isSubmitted()) {
            throw ValidationException::withMessages(['status' => 'Avaliação já enviada: não pode ser alterada nem reenviada.']);
        }

        return $evaluation;
    }

    /**
     * @param  array{comments?: ?string, scores?: list<array<string, mixed>>}  $data
     */
    private function apply(Evaluation $evaluation, array $data): void
    {
        if (array_key_exists('comments', $data)) {
            $evaluation->forceFill(['comments' => $data['comments']])->save();
        }

        $items = $data['scores'] ?? [];

        if ($items === []) {
            return;
        }

        $criteria = EvaluationCriterion::query()
            ->where('event_id', $evaluation->event_id)
            ->where('active', true)
            ->whereIn('id', array_column($items, 'criterion_id'))
            ->get()
            ->keyBy('id');

        foreach ($items as $index => $item) {
            $criterion = $criteria->get((int) $item['criterion_id']);

            if ($criterion === null) {
                throw ValidationException::withMessages(["scores.{$index}.criterion_id" => 'Critério inexistente, inativo ou de outro evento.']);
            }

            if ($item['score'] === null) {
                EvaluationScore::query()->where('evaluation_id', $evaluation->id)->where('criterion_id', $criterion->id)->delete();

                continue;
            }

            $score = (float) $item['score'];

            if ($score < (float) $criterion->min_score || $score > (float) $criterion->max_score) {
                throw ValidationException::withMessages([
                    "scores.{$index}.score" => "A nota de {$criterion->name} vai de {$criterion->min_score} a {$criterion->max_score}.",
                ]);
            }

            EvaluationScore::query()->updateOrCreate(
                ['evaluation_id' => $evaluation->id, 'criterion_id' => $criterion->id],
                ['event_id' => $evaluation->event_id, 'score' => $score, 'comment' => $item['comment'] ?? null],
            );
        }
    }

    /**
     * Auditoria registra a transição (quem, qual avaliação, jurado, equipe), não notas nem comentários.
     *
     * @return array<string, mixed>
     */
    private function transitionSnapshot(Evaluation $evaluation): array
    {
        return [
            'evaluation_id' => $evaluation->id,
            'juror_id' => $evaluation->juror_id,
            'team_id' => $evaluation->team_id,
            'status' => $evaluation->status->value,
            'submitted_at' => $evaluation->submitted_at?->toIso8601String(),
        ];
    }
}
