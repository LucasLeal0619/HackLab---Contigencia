<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\SectorOptionResource;
use App\Models\Event;
use App\Models\Occurrence;
use App\Models\Task;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * Destinos válidos para encaminhar (ou criar para outro setor) pendências e ocorrências:
 * os setores ativos do evento, para quem tem a permissão "route" do tipo de demanda.
 */
class DemandRoutingController extends Controller
{
    public function taskSectors(Event $event): AnonymousResourceCollection
    {
        Gate::authorize('routingSectors', [Task::class, $event]);

        return $this->activeSectors($event);
    }

    public function occurrenceSectors(Event $event): AnonymousResourceCollection
    {
        Gate::authorize('routingSectors', [Occurrence::class, $event]);

        return $this->activeSectors($event);
    }

    private function activeSectors(Event $event): AnonymousResourceCollection
    {
        return SectorOptionResource::collection(
            $event->sectors()->where('active', true)->orderBy('name')->get(['id', 'name']),
        );
    }
}
