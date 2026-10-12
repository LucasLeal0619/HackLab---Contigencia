<?php

namespace App\Domain\Audit;

use App\Models\AuditLog;
use App\Models\Event;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Grava ações sensíveis em audit_logs (única forma de criar um log).
 *
 * Além do que a chamada informa, grava no momento da ação:
 * - fotografia do ator (nome, e-mail, perfil e setor), que não muda se a pessoa mudar depois;
 * - evento do objeto auditado (inferido da entidade; nunca do setor do ator);
 * - rótulo legível da entidade (inferido; snapshot).
 *
 * Nunca grava senha, token, cookie ou segredo: AuditSanitizer remove essas chaves de before/after em qualquer nível.
 */
class AuditLogger
{
    /** Campos usados, em ordem, para o rótulo legível da entidade (só apresentação). */
    private const LABEL_FIELDS = ['name', 'title', 'full_name', 'email', 'code'];

    public function __construct(private readonly Request $request) {}

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     * @param  Event|int|null  $event  explícito quando a entidade não basta para inferir o evento
     */
    public function record(
        string $action,
        string $module,
        string $description,
        ?Model $entity = null,
        ?array $before = null,
        ?array $after = null,
        ?User $actor = null,
        Event|int|null $event = null,
        ?string $entityLabel = null,
    ): AuditLog {
        $actor ??= Auth::guard('web')->user();

        return AuditLog::query()->create([
            ...$this->actorSnapshot($actor),
            'event_id' => $event instanceof Event ? $event->getKey() : ($event ?? $this->eventOf($entity)),
            'action' => $action,
            'module' => $module,
            'entity_type' => $entity ? class_basename($entity) : null,
            'entity_id' => $entity?->getKey() === null ? null : (string) $entity->getKey(),
            'entity_label' => $entityLabel ?? $this->labelOf($entity),
            'description' => $description,
            'before_data' => AuditSanitizer::sanitizeNullable($before),
            'after_data' => AuditSanitizer::sanitizeNullable($after),
            'ip_address' => $this->request->ip(),
            'user_agent' => $this->request->userAgent(),
        ]);
    }

    /**
     * Fotografia do ator agora.
     *
     * @return array<string, mixed>
     */
    private function actorSnapshot(?User $actor): array
    {
        if ($actor === null) {
            return ['actor_user_id' => null, 'actor_person_id' => null];
        }

        // Recarrega só a relação ausente ou que não bate mais com a FK (ex.: perfil alterado nesta requisição).
        foreach (['person' => 'person_id', 'role' => 'role_id', 'sector' => 'sector_id'] as $relation => $foreignKey) {
            if (! $actor->relationLoaded($relation) || $actor->getRelation($relation)?->getKey() != $actor->getAttribute($foreignKey)) {
                $actor->load($relation);
            }
        }

        return [
            'actor_user_id' => $actor->getKey(),
            'actor_person_id' => $actor->person_id,
            'actor_name' => $actor->person?->full_name,
            'actor_email' => $actor->email,
            'actor_role_code' => $actor->role?->code?->value,
            'actor_role_name' => $actor->role?->name,
            'actor_sector_id' => $actor->sector_id,
            'actor_sector_name' => $actor->sector_id === null ? null : $actor->sector?->name,
        ];
    }

    /**
     * Evento do objeto auditado: o próprio Event, auditEventId() do model ou a coluna event_id da entidade.
     * User, Person e entidades sem evento ficam globais (null).
     */
    private function eventOf(?Model $entity): ?int
    {
        return match (true) {
            $entity === null => null,
            $entity instanceof Event => $entity->getKey(),
            method_exists($entity, 'auditEventId') => $entity->auditEventId(),
            array_key_exists('event_id', $entity->getAttributes()) => $entity->getAttribute('event_id') === null ? null : (int) $entity->getAttribute('event_id'),
            default => null,
        };
    }

    /**
     * Rótulo histórico: auditLabel() do model, "reference · title", reference, name, title, full_name, email, code
     * ou "Tipo #id".
     */
    private function labelOf(?Model $entity): ?string
    {
        if ($entity === null) {
            return null;
        }

        $label = method_exists($entity, 'auditLabel') ? $entity->auditLabel() : null;

        if ($label === null) {
            $reference = $entity->getAttribute('reference');
            $title = $entity->getAttribute('title');

            $label = match (true) {
                is_string($reference) && is_string($title) => "{$reference} · {$title}",
                is_string($reference) => $reference,
                default => collect(self::LABEL_FIELDS)->map(fn (string $field) => $entity->getAttribute($field))
                    ->first(fn ($value) => is_string($value) && $value !== ''),
            };
        }

        return mb_substr($label ?? class_basename($entity).' #'.$entity->getKey(), 0, 255);
    }
}
