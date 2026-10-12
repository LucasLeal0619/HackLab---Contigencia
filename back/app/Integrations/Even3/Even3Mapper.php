<?php

namespace App\Integrations\Even3;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Formatos da Even3 (API V2 e webhook) → arrays neutros do HackLab. Nenhuma regra de negócio aqui.
 *
 * Fatos da V2 (PoC real + doc oficial): entrada sem id no topo (identidade = prices[].ticket_id);
 * participante só com id próprio, categoria em texto e sem status de cancelamento.
 */
class Even3Mapper
{
    public const ACTION_PENDING = 'PENDING';

    public const ACTION_CONFIRMED = 'CONFIRMED';

    public const ACTION_CANCELLED = 'CANCELLED';

    public const ACTION_CHECKIN_EVENT = 'CHECKIN_EVENT';

    public const ACTION_CHECKIN_ACTIVITY = 'CHECKIN_ACTIVITY';

    public const ACTION_IGNORED = 'IGNORED';

    /**
     * Gatilhos tratados, pelo nome oficial (doc "Gatilhos de participantes"), normalizado.
     */
    private const TRIGGERS_BY_NAME = [
        'participante com inscricao pendente no evento' => self::ACTION_PENDING,
        'participante com inscricao confirmada no evento' => self::ACTION_CONFIRMED,
        'participante com inscricao cancelada no evento' => self::ACTION_CANCELLED,
        'participante realizou check-in no evento' => self::ACTION_CHECKIN_EVENT,
        'participante realizou check-in na atividade' => self::ACTION_CHECKIN_ACTIVITY,
    ];

    /**
     * @param  array<string, mixed>  $event
     * @return array<string, mixed>
     */
    public function event(array $event): array
    {
        return [
            'external_event_id' => isset($event['id']) ? (string) $event['id'] : null,
            'title' => $this->string($event['title'] ?? null),
            'start_date' => $this->string($event['start_date'] ?? null),
            'end_date' => $this->string($event['end_date'] ?? null),
            'city' => $this->string($event['city'] ?? null),
            'state' => $this->string($event['state'] ?? null),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $tickets
     * @return array{tickets: list<array{external_ticket_id: string, name: string}>, problems: list<array{problem: string, name: string}>}
     */
    public function tickets(array $tickets): array
    {
        $result = ['tickets' => [], 'problems' => []];

        foreach ($tickets as $ticket) {
            $name = $this->string($ticket['title'] ?? null) ?? '';
            $ids = collect(is_array($ticket['prices'] ?? null) ? $ticket['prices'] : [])
                ->pluck('ticket_id')
                ->filter(fn ($id) => $id !== null && $id !== '')
                ->map(fn ($id) => (string) $id)
                ->unique()
                ->values();

            if ($ids->count() === 1) {
                $result['tickets'][] = ['external_ticket_id' => $ids->first(), 'name' => $name];
            } else {
                $result['problems'][] = ['problem' => $ids->isEmpty() ? 'TICKET_WITHOUT_ID' : 'TICKET_ID_AMBIGUOUS', 'name' => $name];
            }
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $attendee
     * @return array<string, mixed>
     */
    public function attendee(array $attendee): array
    {
        return [
            'external_attendee_id' => isset($attendee['id']) ? (string) $attendee['id'] : null,
            'external_event_id' => isset($attendee['event_id']) ? (string) $attendee['event_id'] : null,
            'external_person_id' => null,
            'external_registration_id' => null,
            'name' => $this->string($attendee['name'] ?? null),
            'badge_name' => $this->string($attendee['badge_name'] ?? null),
            'email' => $this->string($attendee['email'] ?? null),
            'document' => $this->string($attendee['document_number'] ?? null),
            'phone' => null,
            'check_in_code' => isset($attendee['check_in_code']) && $attendee['check_in_code'] !== '' ? (string) $attendee['check_in_code'] : null,
            'confirmed' => ($attendee['is_confirmed'] ?? null) === true,
            'category_label' => $this->string($attendee['registration_category'] ?? null),
            'registration_date' => $this->string($attendee['registration_date'] ?? null),
            'registration_hour' => $this->string($attendee['registration_hour'] ?? null),
        ];
    }

    /**
     * Payload do webhook (doc oficial; formato exato a confirmar no PoC real). Aceita os blocos no topo
     * ou dentro de "data".
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function webhook(array $payload): array
    {
        $data = is_array($payload['data'] ?? null) ? $payload['data'] + $payload : $payload;
        $type = is_array($payload['type'] ?? null) ? $payload['type'] : [];
        $typeId = isset($type['id']) && is_numeric($type['id']) ? (int) $type['id'] : null;
        $typeName = $this->string($type['name'] ?? null) ?? '';
        $person = is_array($data['pessoa'] ?? null) ? $data['pessoa'] : [];
        $registration = is_array($data['inscricao'] ?? null) ? $data['inscricao'] : [];
        $event = is_array($data['evento'] ?? null) ? $data['evento'] : [];
        $activity = is_array($data['atividade'] ?? null) ? $data['atividade'] : [];
        $created = $payload['created'] ?? null;

        return [
            'delivery_id' => $this->string(isset($payload['id']) ? (string) $payload['id'] : null),
            'type_id' => $typeId,
            'type_name' => $typeName,
            // Despacho só pelo nome oficial do gatilho. O type.id é guardado no recibo, mas não decide nada:
            // a documentação só mostra o id 2 (confirmada no evento) num exemplo; os demais não são inventados.
            'action' => self::TRIGGERS_BY_NAME[$this->normalizeTrigger($typeName)] ?? self::ACTION_IGNORED,
            'observed_at' => is_numeric($created) ? CarbonImmutable::createFromTimestampUTC((int) $created) : null,
            'external_event_id' => isset($event['id']) ? (string) $event['id'] : null,
            'external_activity_id' => isset($activity['id']) ? (string) $activity['id'] : null,
            'attendee' => [
                'external_attendee_id' => null,
                'external_event_id' => isset($event['id']) ? (string) $event['id'] : null,
                'external_person_id' => isset($person['id']) ? (string) $person['id'] : null,
                'external_registration_id' => isset($registration['id']) ? (string) $registration['id'] : null,
                'name' => $this->string($person['nome'] ?? null),
                'badge_name' => $this->string($person['nome_para_cracha'] ?? null),
                'email' => $this->string($person['email'] ?? null),
                'document' => $this->string($person['cpf'] ?? null) ?? $this->string($person['passaporte'] ?? null),
                'phone' => $this->string($person['celular'] ?? null) ?? $this->string($person['telefone_fixo'] ?? null),
                // inscricao.codigo: não confirmado que é o mesmo check_in_code da API (PoC do webhook pendente).
                // Não é usado como código de credenciamento nem para casar inscrições até ser observado.
                'check_in_code' => null,
                'confirmed' => null,
                'category_label' => $this->string($registration['titulo'] ?? null),
                'registered_at_iso' => $this->string($registration['data_inscricao'] ?? null),
                'registration_date' => null,
                'registration_hour' => null,
            ],
        ];
    }

    public function normalizeTrigger(string $name): string
    {
        return Str::of($name)->ascii()->lower()->squish()->value();
    }

    private function string(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
