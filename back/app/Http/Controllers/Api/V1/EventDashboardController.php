<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Events\EventDashboard;
use App\Http\Controllers\Controller;
use App\Models\Event;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Resumo do Dashboard: 1 requisição com as contagens dos módulos permitidos (no lugar de ~11 listagens).
 */
class EventDashboardController extends Controller
{
    public function __invoke(Request $request, Event $event, EventDashboard $dashboard): JsonResponse
    {
        Gate::authorize('view', $event);

        return response()->json(['data' => $dashboard->build($event, $request->user())]);
    }
}
