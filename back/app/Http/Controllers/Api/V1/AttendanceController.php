<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Integrations\Enums\RegistrationStatus;
use App\Domain\Integrations\Enums\TicketCategory;
use App\Http\Controllers\Controller;
use App\Models\AttendanceRecord;
use App\Models\Event;
use App\Models\EventDay;
use App\Models\EventIntegration;
use App\Models\EventRegistration;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Presença derivada: por inscrição, os dias com ao menos um check-in e o último check-in observado.
 * Os registros individuais ficam no detalhe da inscrição (GET /event-registrations/{id}).
 */
class AttendanceController extends Controller
{
    /**
     * Filtros: event_day_id (presentes naquele dia), internal_category, status, search.
     */
    public function index(Request $request, Event $event): JsonResponse
    {
        Gate::authorize('viewAttendance', EventIntegration::class);

        $filters = $request->validate([
            'event_day_id' => ['sometimes', 'integer', Rule::exists('event_days', 'id')->where('event_id', $event->id)],
            'internal_category' => ['sometimes', Rule::enum(TicketCategory::class)],
            'status' => ['sometimes', Rule::enum(RegistrationStatus::class)],
            'search' => ['sometimes', 'nullable', 'string', 'max:200'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $page = EventRegistration::query()
            ->where('event_registrations.event_id', $event->id)
            ->whereHas('attendance', fn ($q) => isset($filters['event_day_id']) ? $q->where('event_day_id', $filters['event_day_id']) : $q)
            ->when($filters['internal_category'] ?? null, fn ($q, string $v) => $q->whereHas('ticketType', fn ($t) => $t->where('internal_category', $v)))
            ->when($filters['status'] ?? null, fn ($q, string $v) => $q->where('event_registrations.status', $v))
            ->when($filters['search'] ?? null, function ($q, string $search) {
                $like = '%'.addcslashes($search, '%_\\').'%';
                $q->where(fn ($w) => $w->whereHas('person', fn ($p) => $p->where('full_name', 'ilike', $like))->orWhere('event_registrations.source_name', 'ilike', $like));
            })
            ->with('person:id,full_name', 'ticketType:id,external_name,internal_category')
            ->withCount('attendance')
            ->withMax('attendance', 'observed_at')
            ->orderBy('event_registrations.id')
            ->paginate((int) ($filters['per_page'] ?? 50))
            ->withQueryString();

        $days = EventDay::query()->where('event_id', $event->id)->get(['id', 'date', 'label'])->keyBy('id');
        $presence = AttendanceRecord::query()
            ->whereIn('event_registration_id', $page->getCollection()->pluck('id'))
            ->whereNotNull('event_day_id')
            ->select('event_registration_id', 'event_day_id')->distinct()->get()
            ->groupBy('event_registration_id');

        return response()->json([
            'data' => $page->getCollection()->map(fn (EventRegistration $r) => [
                'registration_id' => $r->id,
                'status' => $r->status?->value,
                'person' => ['id' => $r->person->id, 'full_name' => $r->person->full_name],
                'ticket' => $r->ticketType === null ? null : ['id' => $r->ticketType->id, 'external_name' => $r->ticketType->external_name, 'internal_category' => $r->ticketType->internal_category?->value],
                'days_present' => ($presence->get($r->id) ?? collect())->map(fn ($row) => [
                    'event_day_id' => (int) $row->event_day_id,
                    'date' => $days->get($row->event_day_id)?->date?->toDateString(),
                    'label' => $days->get($row->event_day_id)?->label,
                ])->sortBy('date')->values(),
                'check_ins' => $r->attendance_count,
                'last_observed_at' => $r->attendance_max_observed_at === null ? null : Carbon::parse($r->attendance_max_observed_at)->toIso8601String(),
            ])->values(),
            'links' => ['first' => $page->url(1), 'last' => $page->url($page->lastPage()), 'prev' => $page->previousPageUrl(), 'next' => $page->nextPageUrl()],
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
        ]);
    }
}
