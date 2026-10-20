<?php

namespace App\Domain\Integrations;

use App\Domain\Companies\CompanyRepresentativeService;
use App\Domain\Integrations\Enums\RegistrationStatus;
use App\Domain\Integrations\Enums\SyncStatus;
use App\Domain\Integrations\Enums\TicketCategory;
use App\Models\Company;
use App\Models\CompanyRepresentative;
use App\Models\Event;
use App\Models\EventIntegration;
use App\Models\IntegrationSyncRun;
use App\Models\Person;
use Closure;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Even3 simplificada (pré-deploy): o que é seguro a sincronização aplica sozinha; aqui ficam as consultas do
 * resumo da tela Presença e da lista única "Precisa de atenção", derivadas das inscrições (sem tabela de decisão).
 *
 * Inscrição confiável = CONFIRMED, sem identity_mismatch, desta integração, em entrada com a categoria mapeada
 * (nunca pelo texto do nome). Tudo contado por Person. Só consultas, exceto o vínculo manual de representante.
 * Não chama a Even3 e não devolve token, segredo nem documento.
 */
class Even3Attention
{
    public const PARTICIPANT_NOT_FOUND = 'PARTICIPANT_NOT_FOUND';

    public const IDENTITY_CONFLICT = 'IDENTITY_CONFLICT';

    public const COMPANY_UNLINKED = 'COMPANY_UNLINKED';

    public const UNMAPPED_TICKET = 'UNMAPPED_TICKET';

    public const TYPES = [self::PARTICIPANT_NOT_FOUND, self::IDENTITY_CONFLICT, self::COMPANY_UNLINKED, self::UNMAPPED_TICKET];

    private const MAX_ITEMS = 500;

    public function __construct(private readonly CompanyRepresentativeService $representatives) {}

    /**
     * person_id das inscrições confiáveis de uma categoria.
     */
    public static function trustedPeople(EventIntegration $integration, TicketCategory $category): QueryBuilder
    {
        return self::confirmed($integration)
            ->join('external_ticket_types as t', 't.id', '=', 'r.external_ticket_type_id')
            ->where('r.identity_mismatch', false)
            ->where('t.internal_category', $category->value)
            ->select('r.person_id')->distinct();
    }

    /**
     * Resumo da tela Presença e do cabeçalho (só contagens).
     *
     * @return array<string, mixed>
     */
    public function summary(Event $event): array
    {
        $integration = EventIntegration::even3For($event->id);

        if ($integration === null) {
            return ['event_id' => $event->id, 'configured' => false, 'integration_status' => null, 'external_event_id' => null, 'external_event_title' => null,
                'sync' => ['running' => false, 'running_since' => null, 'last_sync_at' => null, 'last_sync_status' => null, 'last_error_code' => null],
                'totals' => null, 'attention' => null, 'tickets' => null, 'event_differences' => 0];
        }

        $running = IntegrationSyncRun::query()->where('event_integration_id', $integration->id)
            ->where('status', SyncStatus::Running->value)
            ->where('started_at', '>=', now()->subMinutes(IntegrationSyncService::STALE_MINUTES))
            ->orderByDesc('started_at')->first();

        $registered = (int) DB::query()->fromSub(self::confirmed($integration)->select('r.person_id')->distinct(), 'x')->count();
        $present = (int) DB::query()->fromSub(self::confirmed($integration)
            ->whereExists(fn (QueryBuilder $q) => $q->from('attendance_records as a')->whereColumn('a.event_registration_id', 'r.id'))
            ->select('r.person_id')->distinct(), 'x')->count();
        $byType = collect($this->items($integration))->countBy('type');
        $tickets = DB::table('external_ticket_types')->where('event_integration_id', $integration->id)->where('active', true)
            ->selectRaw('count(*) as total, count(internal_category) as mapped')->first();

        return [
            'event_id' => $event->id,
            'configured' => true,
            'integration_status' => $integration->status->value,
            'external_event_id' => $integration->external_event_id,
            'external_event_title' => $integration->external_event_title,
            'sync' => [
                'running' => $running !== null,
                'running_since' => $running?->started_at?->toIso8601String(),
                'last_sync_at' => $integration->last_sync_at?->toIso8601String(),
                'last_sync_status' => $integration->last_sync_status?->value,
                'last_error_code' => $integration->last_error_code,
            ],
            'totals' => [
                'registered' => $registered,
                'present' => $present,
                'without_record' => $registered - $present,
                'public_without_access' => Person::query()->whereIn('people.id', self::trustedPeople($integration, TicketCategory::Public))
                    ->whereNotExists($this->accountOf())->count(),
            ],
            'attention' => array_merge(array_fill_keys(self::TYPES, 0), $byType->all(), ['total' => $byType->sum()]),
            'tickets' => ['active' => (int) $tickets->total, 'mapped' => (int) $tickets->mapped],
            'event_differences' => count(array_filter($this->eventDifferences($event, $integration), fn ($row) => $row['different'])),
        ];
    }

    /**
     * Lista única "Precisa de atenção": só o que exige decisão humana.
     *
     * @return list<array<string, mixed>>
     */
    public function items(EventIntegration $integration): array
    {
        $items = [];

        // Participante confirmado sem Participant no evento: cadastro faltando, e-mail/documento diferente ou inscrição errada.
        foreach ($this->people(self::trustedPeople($integration, TicketCategory::Participant), fn (QueryBuilder $q) => $q->from('participants as p')
            ->whereColumn('p.person_id', 'people.id')->where('p.event_id', $integration->event_id)) as $person) {
            $items[] = $this->item(self::PARTICIPANT_NOT_FOUND, $person, 'Participante', $this->registrationOf($integration, $person->id, TicketCategory::Participant));
        }

        // Representante sem representação ativa numa empresa do evento: o Administrador escolhe a empresa.
        foreach ($this->people(self::trustedPeople($integration, TicketCategory::CompanyGuest), fn (QueryBuilder $q) => $q->from('company_representatives as cr')
            ->join('companies as c', 'c.id', '=', 'cr.company_id')
            ->whereColumn('cr.person_id', 'people.id')->where('c.event_id', $integration->event_id)->where('cr.active', true)) as $person) {
            $items[] = $this->item(self::COMPANY_UNLINKED, $person, 'Empresa / Convidado', $this->registrationOf($integration, $person->id, TicketCategory::CompanyGuest));
        }

        // Identidade divergente (e-mail/documento da Even3 diferente do cadastro): nada foi aplicado.
        $conflicts = self::confirmed($integration)->where('r.identity_mismatch', true)
            ->leftJoin('external_ticket_types as t', 't.id', '=', 'r.external_ticket_type_id')
            ->join('people', 'people.id', '=', 'r.person_id')
            ->orderBy('people.full_name')->orderBy('r.id')
            ->limit(self::MAX_ITEMS)
            ->get(['r.id as registration_id', 'r.person_id', 'people.full_name', 'people.email', 't.external_name'])
            ->unique('person_id');
        foreach ($conflicts as $row) {
            $items[] = ['type' => self::IDENTITY_CONFLICT, 'person' => ['id' => $row->person_id, 'full_name' => $row->full_name, 'email' => $row->email],
                'category' => $row->external_name, 'registration_id' => $row->registration_id, 'ticket' => null];
        }

        // Entrada ativa com inscrições e sem mapeamento: ninguém dela é tratado até o mapeamento.
        $unmapped = DB::table('external_ticket_types as t')->where('t.event_integration_id', $integration->id)
            ->where('t.active', true)->whereNull('t.internal_category')
            ->whereExists(fn (QueryBuilder $q) => $q->from('event_registrations as r')->whereColumn('r.external_ticket_type_id', 't.id'))
            ->orderBy('t.external_name')
            ->get(['t.id', 't.external_name']);
        foreach ($unmapped as $ticket) {
            $items[] = ['type' => self::UNMAPPED_TICKET, 'person' => null, 'category' => null, 'registration_id' => null,
                'ticket' => ['id' => $ticket->id, 'name' => $ticket->external_name,
                    'registrations' => DB::table('event_registrations')->where('external_ticket_type_id', $ticket->id)->count()]];
        }

        return array_slice($items, 0, self::MAX_ITEMS);
    }

    /**
     * Nome e datas: HackLab × último retrato da Even3. Só informativo (nada é aplicado).
     *
     * @return list<array{field: string, hacklab: ?string, even3: ?string, different: bool}>
     */
    public function eventDifferences(Event $event, ?EventIntegration $integration): array
    {
        if ($integration === null) {
            return [];
        }

        $even3 = [
            'name' => filled($integration->external_event_title) ? trim($integration->external_event_title) : null,
            'start_date' => $integration->external_start_date?->toDateString(),
            'end_date' => $integration->external_end_date?->toDateString(),
        ];
        $hacklab = ['name' => $event->name, 'start_date' => $event->start_date?->toDateString(), 'end_date' => $event->end_date?->toDateString()];

        return array_map(fn (string $field) => [
            'field' => $field,
            'hacklab' => $hacklab[$field],
            'even3' => $even3[$field],
            'different' => $even3[$field] !== null && ($field === 'name'
                ? IdentityNormalizer::label($even3[$field]) !== IdentityNormalizer::label((string) $hacklab[$field])
                : $even3[$field] !== $hacklab[$field]),
        ], ['name', 'start_date', 'end_date']);
    }

    /**
     * Pendência "Representante sem empresa": o Administrador escolhe a empresa do evento.
     *
     * @param  array{company_id: int, title?: ?string, is_primary?: bool}  $data
     */
    public function linkRepresentative(Event $event, Person $person, array $data): CompanyRepresentative
    {
        $integration = EventIntegration::even3For($event->id)
            ?? throw ValidationException::withMessages(['integration' => 'Este evento não tem integração Even3 configurada.']);
        $company = Company::query()->where('event_id', $event->id)->find($data['company_id'])
            ?? throw ValidationException::withMessages(['company_id' => 'Empresa inexistente ou de outro evento.']);

        $pending = collect($this->items($integration))
            ->contains(fn (array $item) => $item['type'] === self::COMPANY_UNLINKED && $item['person']['id'] === $person->id);
        if (! $pending) {
            throw ValidationException::withMessages(['person' => 'Esta pessoa não é um representante pendente de empresa.']);
        }

        return $this->representatives->add($company, [
            'person_id' => $person->id,
            'title' => $data['title'] ?? null,
            'is_primary' => (bool) ($data['is_primary'] ?? false),
        ])->load('person', 'company');
    }

    private static function confirmed(EventIntegration $integration): QueryBuilder
    {
        return DB::table('event_registrations as r')
            ->where('r.event_integration_id', $integration->id)
            ->where('r.event_id', $integration->event_id)
            ->where('r.status', RegistrationStatus::Confirmed->value);
    }

    /**
     * @return Collection<int, Person>
     */
    private function people(QueryBuilder $ids, Closure $resolved)
    {
        return Person::query()->whereIn('people.id', $ids)->whereNotExists($resolved)
            ->orderBy('people.full_name')->orderBy('people.id')->limit(self::MAX_ITEMS)
            ->get(['people.id', 'people.full_name', 'people.email']);
    }

    private function registrationOf(EventIntegration $integration, int $personId, TicketCategory $category): ?int
    {
        return self::confirmed($integration)->join('external_ticket_types as t', 't.id', '=', 'r.external_ticket_type_id')
            ->where('r.person_id', $personId)->where('t.internal_category', $category->value)->orderBy('r.id')->value('r.id');
    }

    /**
     * @return array<string, mixed>
     */
    private function item(string $type, Person $person, string $category, ?int $registrationId): array
    {
        return ['type' => $type, 'person' => ['id' => $person->id, 'full_name' => $person->full_name, 'email' => $person->email],
            'category' => $category, 'registration_id' => $registrationId, 'ticket' => null];
    }

    private function accountOf(): Closure
    {
        return fn (QueryBuilder $q) => $q->from('users as u')
            ->where(fn (QueryBuilder $w) => $w->whereColumn('u.person_id', 'people.id')
                ->orWhereRaw('people.email is not null and lower(u.email) = lower(people.email)'));
    }
}
