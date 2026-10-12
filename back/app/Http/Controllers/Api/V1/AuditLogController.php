<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Audit\AuditActionCatalog;
use App\Http\Controllers\Controller;
use App\Http\Requests\Audit\ListAuditLogsRequest;
use App\Http\Resources\AuditLogResource;
use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;

/**
 * Auditoria global, somente leitura (Administrador). Não existe criação/alteração/exclusão por API:
 * logs nascem só do AuditLogger. Consultar a auditoria não gera auditoria.
 */
class AuditLogController extends Controller
{
    /**
     * Ordem estável: created_at desc, id desc. Busca textual só em campos legíveis (nunca dentro de before/after).
     */
    public function index(ListAuditLogsRequest $request): AnonymousResourceCollection
    {
        $filters = $request->validated();

        $logs = AuditLog::query()
            ->with('event:id,name')
            ->when($filters['search'] ?? null, function ($q, string $search) {
                $like = '%'.addcslashes($search, '%_\\').'%';
                $q->where(fn ($w) => $w
                    ->where('action', 'ilike', $like)
                    ->orWhere('module', 'ilike', $like)
                    ->orWhere('description', 'ilike', $like)
                    ->orWhere('actor_name', 'ilike', $like)
                    ->orWhere('actor_email', 'ilike', $like)
                    ->orWhere('entity_type', 'ilike', $like)
                    ->orWhere('entity_id', 'ilike', $like)
                    ->orWhere('entity_label', 'ilike', $like));
            })
            // event_id prevalece sobre has_event.
            ->when(isset($filters['event_id']), fn ($q) => $q->where('event_id', $filters['event_id']))
            ->when(! isset($filters['event_id']) && array_key_exists('has_event', $filters), fn ($q) => $request->boolean('has_event')
                ? $q->whereNotNull('event_id')
                : $q->whereNull('event_id'))
            ->when($filters['module'] ?? null, fn ($q, string $v) => $q->where('module', $v))
            ->when($filters['action'] ?? null, fn ($q, string $v) => $q->where('action', $v))
            ->when($filters['actor_user_id'] ?? null, fn ($q, $v) => $q->where('actor_user_id', $v))
            ->when($filters['actor_role'] ?? null, fn ($q, string $v) => $q->where('actor_role_code', $v))
            ->when($filters['actor_sector_id'] ?? null, fn ($q, $v) => $q->where('actor_sector_id', $v))
            ->when($filters['entity_type'] ?? null, fn ($q, string $v) => $q->where('entity_type', $v))
            ->when($filters['entity_id'] ?? null, fn ($q, string $v) => $q->where('entity_id', $v))
            ->when($filters['ip_address'] ?? null, fn ($q, string $v) => $q->where('ip_address', $v))
            ->when($filters['date_from'] ?? null, fn ($q, string $v) => $q->where('created_at', '>=', Carbon::parse($v)->utc()))
            ->when($filters['date_to'] ?? null, fn ($q, string $v) => $q->where('created_at', '<=', Carbon::parse($v)->utc()))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? ListAuditLogsRequest::DEFAULT_PER_PAGE))
            ->withQueryString();

        return AuditLogResource::collection($logs);
    }

    public function show(AuditLog $auditLog): AuditLogResource
    {
        Gate::authorize('view', $auditLog);

        return AuditLogResource::make($auditLog->load('event:id,name'))->withDetails();
    }

    /**
     * Catálogo para os filtros da tela (ações e módulos), sem hardcode no frontend.
     */
    public function meta(): JsonResponse
    {
        Gate::authorize('viewAny', AuditLog::class);

        return response()->json(['data' => [
            'modules' => AuditActionCatalog::modules(),
            'actions' => AuditActionCatalog::actions(),
        ]]);
    }
}
