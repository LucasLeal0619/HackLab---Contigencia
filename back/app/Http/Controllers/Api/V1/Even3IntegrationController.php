<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Integrations\Enums\IntegrationStatus;
use App\Domain\Integrations\Enums\RegistrationStatus;
use App\Domain\Integrations\Enums\SyncScope;
use App\Domain\Integrations\Enums\TicketCategory;
use App\Domain\Integrations\IntegrationAdminService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Integrations\ConfigureEven3Request;
use App\Http\Requests\Integrations\SyncRequest;
use App\Http\Requests\Integrations\TicketMappingRequest;
use App\Http\Resources\ExternalTicketTypeResource;
use App\Http\Resources\IntegrationSyncRunResource;
use App\Integrations\Even3\Even3Exception;
use App\Integrations\EventPlatform\EventPlatformProvider;
use App\Models\AttendanceRecord;
use App\Models\Event;
use App\Models\EventIntegration;
use App\Models\EventRegistration;
use App\Models\ExternalTicketType;
use App\Models\IntegrationSyncRun;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Integração Even3 de um evento: status, configuração explícita, entradas/mapeamento e sincronização.
 * Nenhuma resposta contém token, segredo ou fragmento deles.
 */
class Even3IntegrationController extends Controller
{
    public function __construct(private readonly IntegrationAdminService $admin) {}

    /**
     * ?check=1 consulta a Even3 agora (somente leitura) e atualiza last_connection_checked_at.
     */
    public function status(Request $request, Event $event, EventPlatformProvider $provider): JsonResponse
    {
        Gate::authorize('view', EventIntegration::class);

        $integration = EventIntegration::even3For($event->id);
        $connectionError = null;

        if ($integration !== null && $request->boolean('check') && config('services.even3.enabled') && filled(config('services.even3.token'))) {
            try {
                $external = $provider->fetchEvent($integration->external_event_id);
                $integration->forceFill(['external_event_title' => $external['title'] ?? $integration->external_event_title, 'last_connection_checked_at' => now(), 'last_error_code' => null])->save();
            } catch (Even3Exception $e) {
                $connectionError = $e->errorCode;
                $integration->forceFill(['last_error_code' => $e->errorCode, 'last_error_at' => now()])->save();
            }
        }

        $registrations = $integration === null ? null : EventRegistration::query()->where('event_integration_id', $integration->id);

        return response()->json(['data' => [
            'enabled' => (bool) config('services.even3.enabled'),
            'token_configured' => filled(config('services.even3.token')),
            'webhook_configured' => filled(config('services.even3.webhook_header')) && filled(config('services.even3.webhook_secret')),
            'configured' => $integration !== null,
            'status' => $integration?->status?->value,
            'connected' => $integration !== null && $integration->last_connection_checked_at !== null && ! in_array($integration->last_error_code, [Even3Exception::UNAUTHORIZED, Even3Exception::FORBIDDEN, Even3Exception::NOT_FOUND], true),
            'connection_error' => $connectionError,
            'api_version' => $integration?->api_version,
            'external_event_id' => $integration?->external_event_id,
            'external_event_title' => $integration?->external_event_title,
            'last_connection_checked_at' => $integration?->last_connection_checked_at?->toIso8601String(),
            'last_sync_at' => $integration?->last_sync_at?->toIso8601String(),
            'last_sync_status' => $integration?->last_sync_status?->value,
            'last_error_code' => $integration?->last_error_code,
            'ticket_count' => $integration === null ? 0 : ExternalTicketType::query()->where('event_integration_id', $integration->id)->count(),
            'unmapped_ticket_count' => $integration === null ? 0 : ExternalTicketType::query()->where('event_integration_id', $integration->id)->where('active', true)->whereNull('internal_category')->count(),
            'registration_count' => $registrations?->clone()->count() ?? 0,
            'confirmed_count' => $registrations?->clone()->where('status', RegistrationStatus::Confirmed->value)->count() ?? 0,
            'attendance_count' => $integration === null ? 0 : AttendanceRecord::query()->where('event_integration_id', $integration->id)->count(),
            'timezone' => $event->timezone,
        ]]);
    }

    public function configure(ConfigureEven3Request $request, Event $event): JsonResponse
    {
        ['integration' => $integration, 'warnings' => $warnings] = $this->admin->configure(
            $event,
            $request->validated('external_event_id'),
            IntegrationStatus::from($request->validated('status')),
            $request->user(),
        );

        return response()->json([
            'data' => [
                'id' => $integration->id,
                'event_id' => $integration->event_id,
                'provider' => $integration->provider,
                'api_version' => $integration->api_version,
                'external_event_id' => $integration->external_event_id,
                'external_event_title' => $integration->external_event_title,
                'status' => $integration->status->value,
                'last_connection_checked_at' => $integration->last_connection_checked_at?->toIso8601String(),
            ],
            'meta' => ['warnings' => $warnings],
        ]);
    }

    public function tickets(Request $request, Event $event): AnonymousResourceCollection
    {
        Gate::authorize('view', EventIntegration::class);

        $integration = $this->integrationOrFail($event);

        return ExternalTicketTypeResource::collection(
            ExternalTicketType::query()->where('event_integration_id', $integration->id)
                ->withCount('registrations')
                ->when($request->has('active'), fn ($q) => $q->where('active', $request->boolean('active')))
                ->when($request->has('mapped'), fn ($q) => $request->boolean('mapped') ? $q->whereNotNull('internal_category') : $q->whereNull('internal_category'))
                ->orderBy('external_name')
                ->get(),
        )->additional(['meta' => ['categories' => array_map(fn (TicketCategory $c) => $c->value, TicketCategory::cases())]]);
    }

    public function mapping(TicketMappingRequest $request, ExternalTicketType $ticket): ExternalTicketTypeResource
    {
        $category = $request->validated('internal_category');

        return ExternalTicketTypeResource::make($this->admin->changeMapping($ticket, $category === null ? null : TicketCategory::from($category), $request->user()));
    }

    /**
     * Sync manual (síncrono). 409 se já houver um em andamento para a integração.
     */
    public function sync(SyncRequest $request, Event $event): JsonResponse
    {
        $integration = $this->integrationOrFail($event);
        $run = $this->admin->triggerSync($integration, SyncScope::from($request->validated('scope', SyncScope::All->value)), $request->user());

        return IntegrationSyncRunResource::make($run)->withDetails()->response()->setStatusCode(201);
    }

    public function syncRuns(Request $request, Event $event): AnonymousResourceCollection
    {
        Gate::authorize('view', EventIntegration::class);

        $integration = $this->integrationOrFail($event);
        $request->attributes->set(IntegrationSyncRunResource::DETAILS_ATTRIBUTE, $request->user()->can('viewSyncDetails', EventIntegration::class));

        $runs = IntegrationSyncRun::query()->where('event_integration_id', $integration->id)
            ->when($request->query('status'), fn ($q, string $v) => $q->where('status', $v))
            ->orderByDesc('started_at')->orderByDesc('id')
            ->paginate(min(max((int) $request->query('per_page', 20), 1), 100));

        return IntegrationSyncRunResource::collection($runs);
    }

    public function showSyncRun(Request $request, IntegrationSyncRun $run): IntegrationSyncRunResource
    {
        Gate::authorize('view', EventIntegration::class);

        return IntegrationSyncRunResource::make($run)->withDetails($request->user()->can('viewSyncDetails', EventIntegration::class));
    }

    private function integrationOrFail(Event $event): EventIntegration
    {
        return EventIntegration::even3For($event->id)
            ?? throw new NotFoundHttpException('Este evento não tem integração Even3 configurada.');
    }
}
