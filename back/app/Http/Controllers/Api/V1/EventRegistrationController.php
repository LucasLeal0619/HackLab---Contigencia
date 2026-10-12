<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Integrations\Enums\RegistrationStatus;
use App\Domain\Integrations\Enums\TicketCategory;
use App\Domain\Integrations\ParticipantMaterializer;
use App\Http\Controllers\Controller;
use App\Http\Resources\EventRegistrationResource;
use App\Models\Event;
use App\Models\EventIntegration;
use App\Models\EventRegistration;
use App\Models\Juror;
use App\Models\Participant;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Inscrições externas do evento (somente leitura) e a materialização explícita de participantes.
 * Os vínculos (participante, jurado, conta) são só resumo derivado da mesma Person: a consulta não concede nada.
 */
class EventRegistrationController extends Controller
{
    /**
     * Filtros: search, status, internal_category, external_ticket_type_id, confirmed, has_participant,
     * identity_mismatch, unmapped.
     */
    public function index(Request $request, Event $event): JsonResponse
    {
        Gate::authorize('viewRegistrations', EventIntegration::class);

        $filters = $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:200'],
            'status' => ['sometimes', Rule::enum(RegistrationStatus::class)],
            'internal_category' => ['sometimes', Rule::enum(TicketCategory::class)],
            'external_ticket_type_id' => ['sometimes', 'integer'],
            'confirmed' => ['sometimes', Rule::in(['true', 'false', '1', '0'])],
            'has_participant' => ['sometimes', Rule::in(['true', 'false', '1', '0'])],
            'identity_mismatch' => ['sometimes', Rule::in(['true', 'false', '1', '0'])],
            'unmapped' => ['sometimes', Rule::in(['true', 'false', '1', '0'])],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $registrations = $this->filtered(EventRegistration::query()->where('event_registrations.event_id', $event->id), $filters, $event)
            ->with('person', 'ticketType')
            ->join('people', 'people.id', '=', 'event_registrations.person_id')
            ->select('event_registrations.*')
            ->orderBy('people.full_name')
            ->orderBy('event_registrations.id')
            ->paginate((int) ($filters['per_page'] ?? 50));

        return response()->json($this->paginated($registrations->withQueryString(), $request, $this->links($event, $registrations->getCollection())));
    }

    public function show(Request $request, EventRegistration $registration): EventRegistrationResource
    {
        Gate::authorize('viewRegistrations', EventIntegration::class);

        $registration->load('person', 'ticketType');

        if ($request->user()->can('viewAttendance', EventIntegration::class)) {
            $registration->load('attendance');
        }

        $event = Event::query()->findOrFail($registration->event_id);

        return EventRegistrationResource::make($registration)->withLinks($this->links($event, collect([$registration]))[$registration->person_id]);
    }

    public function materialize(Request $request, Event $event, ParticipantMaterializer $materializer): JsonResponse
    {
        Gate::authorize('materializeParticipants', EventIntegration::class);

        return response()->json(['data' => $materializer->materialize($event, $request->user())]);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function filtered(Builder $query, array $filters, Event $event): Builder
    {
        $bool = fn (string $key) => filter_var($filters[$key], FILTER_VALIDATE_BOOLEAN);

        return $query
            ->when($filters['search'] ?? null, function (Builder $q, string $search) {
                $like = '%'.addcslashes($search, '%_\\').'%';
                $q->where(fn ($w) => $w->whereHas('person', fn ($p) => $p->where('full_name', 'ilike', $like)->orWhere('email', 'ilike', $like))
                    ->orWhere('event_registrations.source_name', 'ilike', $like)
                    ->orWhere('event_registrations.source_email', 'ilike', $like)
                    ->orWhere('event_registrations.check_in_code', 'ilike', $like));
            })
            ->when($filters['status'] ?? null, fn (Builder $q, string $v) => $q->where('event_registrations.status', $v))
            ->when(array_key_exists('confirmed', $filters), fn (Builder $q) => $bool('confirmed')
                ? $q->where('event_registrations.status', RegistrationStatus::Confirmed->value)
                : $q->where('event_registrations.status', '<>', RegistrationStatus::Confirmed->value))
            ->when($filters['external_ticket_type_id'] ?? null, fn (Builder $q, $v) => $q->where('event_registrations.external_ticket_type_id', $v))
            ->when($filters['internal_category'] ?? null, fn (Builder $q, string $v) => $q->whereHas('ticketType', fn ($t) => $t->where('internal_category', $v)))
            ->when(array_key_exists('unmapped', $filters), fn (Builder $q) => $bool('unmapped')
                ? $q->where(fn ($w) => $w->whereNull('event_registrations.external_ticket_type_id')->orWhereHas('ticketType', fn ($t) => $t->whereNull('internal_category')))
                : $q->whereHas('ticketType', fn ($t) => $t->whereNotNull('internal_category')))
            ->when(array_key_exists('identity_mismatch', $filters), fn (Builder $q) => $q->where('event_registrations.identity_mismatch', $bool('identity_mismatch')))
            ->when(array_key_exists('has_participant', $filters), function (Builder $q) use ($bool, $event) {
                $exists = fn ($sub) => $sub->selectRaw('1')->from('participants')
                    ->whereColumn('participants.person_id', 'event_registrations.person_id')->where('participants.event_id', $event->id);
                $bool('has_participant') ? $q->whereExists($exists) : $q->whereNotExists($exists);
            });
    }

    /**
     * Resumo derivado por Person (3 consultas por página, sem N+1).
     *
     * @param  Collection<int, EventRegistration>  $registrations
     * @return array<int, array<string, mixed>>
     */
    private function links(Event $event, Collection $registrations): array
    {
        $personIds = $registrations->pluck('person_id')->unique()->values()->all();
        $participants = Participant::query()->where('event_id', $event->id)->whereIn('person_id', $personIds)->get(['id', 'person_id', 'status'])->keyBy('person_id');
        $jurors = Juror::query()->where('event_id', $event->id)->whereIn('person_id', $personIds)->get(['id', 'person_id', 'status'])->keyBy('person_id');
        $users = User::query()->whereIn('person_id', $personIds)->with('role:id,code')->get(['id', 'person_id', 'role_id', 'status'])->keyBy('person_id');

        return collect($personIds)->mapWithKeys(fn (int $personId) => [$personId => [
            'participant' => ($p = $participants->get($personId)) === null ? null : ['id' => $p->id, 'status' => $p->status?->value],
            'juror' => ($j = $jurors->get($personId)) === null ? null : ['id' => $j->id, 'status' => $j->status?->value],
            'user' => ($u = $users->get($personId)) === null ? null : ['id' => $u->id, 'role' => $u->role?->code?->value, 'status' => $u->status?->value],
        ]])->all();
    }

    /**
     * Mesmo formato paginado das demais listagens (data + links + meta).
     *
     * @param  LengthAwarePaginator<int, EventRegistration>  $page
     * @param  array<int, array<string, mixed>>  $links
     * @return array<string, mixed>
     */
    private function paginated($page, Request $request, array $links): array
    {
        return [
            'data' => $page->getCollection()->map(fn (EventRegistration $r) => EventRegistrationResource::make($r)->withLinks($links[$r->person_id])->resolve($request))->values(),
            'links' => ['first' => $page->url(1), 'last' => $page->url($page->lastPage()), 'prev' => $page->previousPageUrl(), 'next' => $page->nextPageUrl()],
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
        ];
    }
}
