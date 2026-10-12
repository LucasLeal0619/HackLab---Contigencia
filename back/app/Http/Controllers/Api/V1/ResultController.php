<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Results\Enums\PublicationStatus;
use App\Domain\Results\ResultPublicationService;
use App\Domain\Results\ResultsCalculator;
use App\Http\Controllers\Controller;
use App\Http\Requests\Results\PublishResultsRequest;
use App\Http\Requests\Results\RevokeResultsRequest;
use App\Http\Resources\ResultPublicationResource;
use App\Models\Event;
use App\Models\ResultPublication;
use App\Models\VotingSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Prévia, publicação, revogação e resultado oficial. Fora da prévia (Administrador), ninguém recebe
 * ranking não publicado: o resultado oficial é sempre o snapshot PUBLISHED.
 */
class ResultController extends Controller
{
    public function __construct(
        private readonly ResultsCalculator $calculator,
        private readonly ResultPublicationService $publications,
    ) {}

    /**
     * ?voting_session_id= escolhe a sessão pública (nunca inferida).
     */
    public function preview(Request $request, Event $event): JsonResponse
    {
        Gate::authorize('preview', ResultPublication::class);

        $sessionId = $request->query('voting_session_id');
        $session = $sessionId === null ? null : VotingSession::query()->find((int) $sessionId);

        return response()->json(['data' => $this->calculator->preview($event, $session)]);
    }

    /**
     * Histórico de versões (publicadas e revogadas), com os snapshots.
     */
    public function publications(Event $event): JsonResponse
    {
        Gate::authorize('preview', ResultPublication::class);

        $publications = ResultPublication::query()->where('event_id', $event->id)->orderByDesc('version')->get()
            ->map(fn (ResultPublication $p) => ResultPublicationResource::make($p)->withAdminData()->resolve())
            ->values();

        return response()->json(['data' => $publications]);
    }

    public function publish(PublishResultsRequest $request, Event $event): JsonResponse
    {
        $publication = $this->publications->publish($event, (int) $request->validated('voting_session_id'), $request->user());

        return ResultPublicationResource::make($publication)->withAdminData()->response()->setStatusCode(201);
    }

    public function revoke(RevokeResultsRequest $request, Event $event): ResultPublicationResource
    {
        return ResultPublicationResource::make($this->publications->revoke($event, $request->validated('reason'), $request->user()))->withAdminData();
    }

    /**
     * Resultado oficial: o snapshot PUBLISHED atual, ou null se não houver.
     */
    public function published(Event $event): JsonResponse
    {
        Gate::authorize('viewPublished', ResultPublication::class);

        $publication = ResultPublication::query()->where('event_id', $event->id)
            ->where('status', PublicationStatus::Published->value)->first();

        return response()->json([
            'data' => $publication === null ? null : ResultPublicationResource::make($publication)->resolve(),
            'meta' => ['official' => $publication !== null],
        ]);
    }
}
