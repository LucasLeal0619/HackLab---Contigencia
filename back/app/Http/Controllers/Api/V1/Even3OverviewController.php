<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Integrations\Even3Attention;
use App\Http\Controllers\Controller;
use App\Http\Requests\Integrations\LinkCompanyGuestRequest;
use App\Http\Resources\CompanyRepresentativeResource;
use App\Models\Event;
use App\Models\EventIntegration;
use App\Models\Person;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Tela Presença / cabeçalho: resumo, "Precisa de atenção", divergência do evento e o único vínculo manual
 * (representante sem empresa). Sincronizar continua no Even3IntegrationController. Nada aqui chama a Even3;
 * consultas não geram auditoria.
 */
class Even3OverviewController extends Controller
{
    public function __construct(private readonly Even3Attention $attention) {}

    public function workbench(Event $event): JsonResponse
    {
        Gate::authorize('view', EventIntegration::class);

        return response()->json(['data' => $this->attention->summary($event)]);
    }

    public function attention(Event $event): JsonResponse
    {
        Gate::authorize('viewAttention', EventIntegration::class);
        $integration = EventIntegration::even3For($event->id);
        $items = $integration === null ? [] : $this->attention->items($integration);

        return response()->json(['data' => $items, 'meta' => ['total' => count($items)]]);
    }

    public function eventMetadata(Event $event): JsonResponse
    {
        Gate::authorize('view', EventIntegration::class);
        $integration = EventIntegration::even3For($event->id);

        return response()->json(['data' => [
            'configured' => $integration !== null,
            'synced_at' => $integration?->external_metadata_synced_at?->toIso8601String(),
            'fields' => $this->attention->eventDifferences($event, $integration),
        ]]);
    }

    public function linkCompanyGuest(LinkCompanyGuestRequest $request, Event $event, Person $person): JsonResponse
    {
        return CompanyRepresentativeResource::make($this->attention->linkRepresentative($event, $person, $request->validated()))
            ->response()->setStatusCode(201);
    }
}
