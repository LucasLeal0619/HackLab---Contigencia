<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Finance\FinancialItemService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\FinancialItemRequest;
use App\Http\Resources\FinancialItemResource;
use App\Models\Event;
use App\Models\FinancialItem;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class FinancialItemController extends Controller
{
    public function __construct(private readonly FinancialItemService $items) {}

    public function index(Event $event): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', [FinancialItem::class, $event]);

        return FinancialItemResource::collection(
            $event->financialItems()->orderBy('category')->orderBy('item')->get()
        );
    }

    public function store(FinancialItemRequest $request, Event $event): FinancialItemResource
    {
        return FinancialItemResource::make($this->items->create($event, $request->validated()));
    }

    public function update(FinancialItemRequest $request, FinancialItem $financialItem): FinancialItemResource
    {
        return FinancialItemResource::make($this->items->update($financialItem, $request->validated()));
    }

    public function destroy(FinancialItem $financialItem): Response
    {
        Gate::authorize('delete', $financialItem);
        $this->items->delete($financialItem);

        return response()->noContent();
    }
}
