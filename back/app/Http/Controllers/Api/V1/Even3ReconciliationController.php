<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Integrations\Enums\ReconciliationKind;
use App\Domain\Integrations\Reconciliation\Even3Workbench;
use App\Domain\Integrations\Reconciliation\EventMetadataReconciler;
use App\Domain\Integrations\Reconciliation\ReconciliationQueue;
use App\Domain\Integrations\Reconciliation\ReconciliationService;
use App\Domain\Users\Enums\RoleCode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Integrations\ApplyEventMetadataRequest;
use App\Http\Requests\Integrations\ConfirmJurorCandidateRequest;
use App\Http\Requests\Integrations\GrantAccessRequest;
use App\Http\Requests\Integrations\LinkCompanyGuestRequest;
use App\Http\Requests\Integrations\ReconciliationDecisionRequest;
use App\Http\Resources\AccountAccessGrantResource;
use App\Http\Resources\CompanyRepresentativeResource;
use App\Http\Resources\EventResource;
use App\Http\Resources\JurorResource;
use App\Models\Event;
use App\Models\EventIntegration;
use App\Models\Person;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Centro Even3 (Complemento 11.2): resumo, filas de reconciliação e divergências do evento.
 * Sincronizar continua sendo o endpoint central do Even3IntegrationController. Nada aqui chama a Even3;
 * consultas não geram auditoria.
 */
class Even3ReconciliationController extends Controller
{
    public function __construct(private readonly ReconciliationService $reconciliation) {}

    public function workbench(Event $event, Even3Workbench $workbench): JsonResponse
    {
        Gate::authorize('view', EventIntegration::class);

        return response()->json(['data' => $workbench->build($event)]);
    }

    public function queue(Request $request, Event $event, string $queue, ReconciliationQueue $queues): JsonResponse
    {
        $kind = ReconciliationKind::fromQueue($queue);
        Gate::authorize(ReconciliationDecisionRequest::abilityFor($kind), EventIntegration::class);

        $view = in_array($request->query('view'), ReconciliationQueue::VIEWS, true) ? $request->query('view') : 'pending';
        $page = $queues->paginate(
            ReconciliationService::integrationOf($event),
            $kind,
            $view,
            $request->query('search') !== null ? trim((string) $request->query('search')) : null,
            min(max((int) $request->query('per_page', 25), 1), 100),
        );

        return response()->json([
            'data' => $page->items(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'queue' => $kind->queue(),
                'view' => $view,
            ],
        ]);
    }

    public function confirmJuror(ConfirmJurorCandidateRequest $request, Event $event, Person $person): JsonResponse
    {
        $juror = $this->reconciliation->confirmJuror($event, $person, $request->validated(), $request->user());

        return JurorResource::make($juror)->response()->setStatusCode(201);
    }

    public function linkCompanyGuest(LinkCompanyGuestRequest $request, Event $event, Person $person): JsonResponse
    {
        $representative = $this->reconciliation->linkCompanyGuest($event, $person, $request->validated(), $request->user());

        return CompanyRepresentativeResource::make($representative)->response()->setStatusCode(201);
    }

    public function grantAccess(GrantAccessRequest $request, Event $event, Person $person): JsonResponse
    {
        $grant = $this->reconciliation->grantOrganizationAccess(
            $event,
            $person,
            RoleCode::from($request->validated('role')),
            $request->validated('sector_id') !== null ? (int) $request->validated('sector_id') : null,
            $request->user(),
        );

        return AccountAccessGrantResource::make($grant)->response()->setStatusCode(201);
    }

    public function ignore(ReconciliationDecisionRequest $request, Event $event, string $queue, Person $person): JsonResponse
    {
        $decision = $this->reconciliation->ignore($event, $request->kind(), $person, $request->validated('note'), $request->user());

        return response()->json(['data' => ['person_id' => $person->id, 'queue' => $queue, 'state' => 'IGNORED', 'note' => $decision->note]]);
    }

    public function restore(ReconciliationDecisionRequest $request, Event $event, string $queue, Person $person): JsonResponse
    {
        $this->reconciliation->restore($event, $request->kind(), $person, $request->user());

        return response()->json(['data' => ['person_id' => $person->id, 'queue' => $queue, 'state' => 'PENDING']]);
    }

    public function eventMetadata(Event $event, EventMetadataReconciler $metadata): JsonResponse
    {
        Gate::authorize('view', EventIntegration::class);
        $integration = EventIntegration::even3For($event->id);

        return response()->json(['data' => [
            'configured' => $integration !== null,
            'synced_at' => $integration?->external_metadata_synced_at?->toIso8601String(),
            'fields' => $metadata->compare($event, $integration),
        ]]);
    }

    public function applyEventMetadata(ApplyEventMetadataRequest $request, Event $event, EventMetadataReconciler $metadata): EventResource
    {
        $updated = $metadata->apply($event, ReconciliationService::integrationOf($event), $request->validated('fields'), $request->user());

        return EventResource::make($updated);
    }
}
