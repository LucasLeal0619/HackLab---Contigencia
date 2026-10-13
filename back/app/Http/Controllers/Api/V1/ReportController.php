<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Reports\ClosingResultsReportBuilder;
use App\Domain\Reports\GeneralReportBuilder;
use App\Domain\Reports\OperationsReportBuilder;
use App\Domain\Reports\ParticipantsPresenceReportBuilder;
use App\Domain\Reports\ReportBuilder;
use App\Http\Controllers\Controller;
use App\Models\Event;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Relatórios consolidados (Fase 11): 1 relatório = 1 endpoint, montado pelo builder do domínio.
 * Somente leitura: não persiste nada e não gera auditoria.
 */
class ReportController extends Controller
{
    public function general(Request $request, Event $event, GeneralReportBuilder $builder): JsonResponse
    {
        return $this->respond($request, $event, $builder);
    }

    public function participantsPresence(Request $request, Event $event, ParticipantsPresenceReportBuilder $builder): JsonResponse
    {
        return $this->respond($request, $event, $builder);
    }

    public function operations(Request $request, Event $event, OperationsReportBuilder $builder): JsonResponse
    {
        return $this->respond($request, $event, $builder);
    }

    public function closingResults(Request $request, Event $event, ClosingResultsReportBuilder $builder): JsonResponse
    {
        return $this->respond($request, $event, $builder);
    }

    private function respond(Request $request, Event $event, ReportBuilder $builder): JsonResponse
    {
        Gate::authorize('viewReports', $event);

        return response()->json(['data' => $builder->build($event, $request->user())]);
    }
}
