<?php

namespace App\Domain\Results;

use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditLogger;
use App\Domain\Results\Enums\PublicationStatus;
use App\Models\Event;
use App\Models\ResultPublication;
use App\Models\User;
use App\Models\VotingSession;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Publicação oficial: snapshot imutável e versionado. Corrigir = revogar (com motivo) e publicar a próxima versão.
 *
 * Publicar, revogar, alterar atribuições e solicitar revisão pegam o lock da linha do evento: a publicação
 * nunca congela um conjunto técnico que outra requisição está mudando no mesmo instante.
 */
class ResultPublicationService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly ResultsCalculator $calculator,
    ) {}

    public function publish(Event $event, int $votingSessionId, User $actor): ResultPublication
    {
        try {
            return DB::transaction(function () use ($event, $votingSessionId, $actor) {
                $event = Event::query()->lockForUpdate()->findOrFail($event->id);

                if (ResultPublication::hasPublishedFor($event->id)) {
                    throw ValidationException::withMessages(['publication' => 'Já existe resultado publicado neste evento. Revogue-o antes de publicar uma nova versão.']);
                }

                $session = VotingSession::query()->where('event_id', $event->id)->sharedLock()->find($votingSessionId);

                if ($session === null) {
                    throw ValidationException::withMessages(['voting_session_id' => 'Sessão de votação inexistente ou de outro evento.']);
                }

                if (! $session->isClosed()) {
                    throw ValidationException::withMessages(['voting_session_id' => "A sessão de votação precisa estar encerrada (status: {$session->status->value})."]);
                }

                $completeness = $this->calculator->completeness($event);

                if (! $completeness['complete']) {
                    throw ValidationException::withMessages([
                        'evaluations' => "{$completeness['pending_count']} atribuição(ões) ativa(s) sem avaliação enviada. Conclua as avaliações ou revogue explicitamente as atribuições que não devem contar.",
                    ]);
                }

                if ($this->calculator->validEvaluationsCount($event) === 0) {
                    throw ValidationException::withMessages([
                        'evaluations' => 'Não há nenhuma avaliação técnica válida (enviada, com atribuição ativa): o resultado oficial não pode ser publicado.',
                    ]);
                }

                $version = (int) ResultPublication::query()->where('event_id', $event->id)->max('version') + 1;
                $publishedAt = now();
                $snapshot = $this->calculator->snapshot($event, $session, $version, $publishedAt->toIso8601String());

                $publication = new ResultPublication([
                    'event_id' => $event->id,
                    'voting_session_id' => $session->id,
                    'version' => $version,
                    'snapshot' => $snapshot,
                    'published_by_user_id' => $actor->id,
                    'published_at' => $publishedAt,
                ]);
                $publication->forceFill(['status' => PublicationStatus::Published])->save();

                $this->audit->record(
                    AuditAction::RESULTS_PUBLISHED,
                    'results',
                    "Resultado oficial publicado (versão {$version}) no evento {$event->name}, com a votação \"{$session->name}\".",
                    entity: $publication,
                    after: [
                        'publication_id' => $publication->id,
                        'version' => $version,
                        'voting_session_id' => $session->id,
                        'technical_has_ties' => $snapshot['technical']['has_ties'],
                        'popular_has_ties' => $snapshot['popular']['has_ties'],
                        'popular_total_votes' => $snapshot['popular']['total_votes'],
                    ],
                );

                return $publication->refresh();
            });
        } catch (UniqueConstraintViolationException) {
            // Corrida entre publicações: os índices (1 PUBLISHED por evento / versão única) barraram a segunda.
            throw ValidationException::withMessages(['publication' => 'Já existe resultado publicado neste evento.']);
        }
    }

    /**
     * PUBLISHED → REVOKED. O snapshot permanece armazenado; o resultado deixa de ser oficial e as operações
     * técnicas voltam a ser permitidas.
     */
    public function revoke(Event $event, string $reason, User $actor): ResultPublication
    {
        return DB::transaction(function () use ($event, $reason, $actor) {
            $event = Event::query()->lockForUpdate()->findOrFail($event->id);

            $publication = ResultPublication::query()->where('event_id', $event->id)
                ->where('status', PublicationStatus::Published->value)->lockForUpdate()->first();

            if ($publication === null) {
                throw ValidationException::withMessages(['publication' => 'Não há resultado publicado para revogar.']);
            }

            $publication->forceFill([
                'status' => PublicationStatus::Revoked,
                'revoked_by_user_id' => $actor->id,
                'revoked_at' => now(),
                'revoke_reason' => $reason,
            ])->save();

            $this->audit->record(
                AuditAction::RESULTS_PUBLICATION_REVOKED,
                'results',
                "Publicação de resultado versão {$publication->version} revogada no evento {$event->name}.",
                entity: $publication,
                before: ['status' => PublicationStatus::Published->value, 'version' => $publication->version],
                after: ['status' => PublicationStatus::Revoked->value, 'version' => $publication->version, 'reason' => $reason],
            );

            return $publication;
        });
    }

    /**
     * Trava técnica: chamada pelas operações que mudariam o resultado técnico, já com o lock do evento.
     */
    public static function ensureNotPublished(int $eventId, string $field, string $operation): void
    {
        if (ResultPublication::hasPublishedFor($eventId)) {
            throw ValidationException::withMessages([
                $field => "Resultado oficial publicado: revogue a publicação antes de {$operation}.",
            ]);
        }
    }
}
