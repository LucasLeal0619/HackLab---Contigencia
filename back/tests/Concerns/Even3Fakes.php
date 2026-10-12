<?php

namespace Tests\Concerns;

use App\Domain\Integrations\Enums\IntegrationStatus;
use App\Domain\Integrations\Enums\TicketCategory;
use App\Models\Event;
use App\Models\EventIntegration;
use App\Models\ExternalTicketType;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Http;

/**
 * Even3 falsa (formatos da API V2 observados no PoC real e da documentação oficial). Nenhum teste usa a rede.
 */
trait Even3Fakes
{
    protected const EVEN3_TOKEN = 'tok-teste-even3-abcdef0123456789';

    protected const EVEN3_WEBHOOK_HEADER = 'X-Webhook-Token';

    protected const EVEN3_WEBHOOK_SECRET = 'segredo-webhook-teste-987';

    protected const EVEN3_BASE = 'https://api.even3.com/api/v2';

    protected const EXTERNAL_EVENT_ID = '793572';

    protected function configureEven3(array $overrides = []): void
    {
        config(['services.even3' => array_replace([
            'enabled' => true,
            'base_url' => self::EVEN3_BASE,
            'token' => self::EVEN3_TOKEN,
            'timeout' => 15,
            'connect_timeout' => 5,
            'retries' => 2,
            'retry_backoff_ms' => 0,
            'page_size' => 100,
            'max_pages' => 50,
            'webhook_header' => self::EVEN3_WEBHOOK_HEADER,
            'webhook_secret' => self::EVEN3_WEBHOOK_SECRET,
        ], $overrides)]);
        Http::preventStrayRequests();
    }

    /**
     * Http::fake empilha stubs (o primeiro que casa vence): para trocar as respostas no meio do teste, reinicia.
     *
     * @param  array<string, mixed>  $stubs
     */
    protected function refakeHttp(array $stubs): void
    {
        Http::swap(new HttpFactory);
        Http::preventStrayRequests();
        Http::fake($stubs);
    }

    /**
     * @return array<string, mixed>
     */
    protected function externalEvent(string $id = self::EXTERNAL_EVENT_ID, string $title = 'Hackathon Teste'): array
    {
        return ['id' => (int) $id, 'title' => $title, 'url' => 'hackathon-teste', 'summary' => '', 'start_date' => '2026-11-04T00:00:00Z',
            'start_time' => '', 'end_date' => '2026-11-06T00:00:00Z', 'end_time' => '', 'registration_start_date' => '', 'registration_end_date' => '',
            'image_url' => '', 'credit_hours' => 0, 'country' => 'Brasil', 'state' => null, 'city' => null, 'venue' => '', 'latitude' => null, 'longitude' => null, 'tickets' => null];
    }

    /**
     * Entrada V2: sem id no topo; identidade em prices[].ticket_id (um ou vários lotes).
     *
     * @return array<string, mixed>
     */
    protected function externalTicket(int $ticketId, string $title, int $lots = 1): array
    {
        return ['title' => $title, 'ticket_confirmed_quantity' => 0, 'prices' => array_map(fn (int $n) => [
            'id' => $ticketId * 100 + $n, 'ticket_id' => $ticketId, 'price' => 0,
            'sales_start_date' => '2026-10-01T00:00:00Z', 'sales_end_date' => '2026-11-04T00:00:00Z', 'lot_confirmed_quantity' => 0,
        ], range(1, $lots))];
    }

    /**
     * @return array<string, mixed>
     */
    protected function externalAttendee(int $id, array $overrides = []): array
    {
        return array_replace([
            'id' => $id, 'event_id' => (int) self::EXTERNAL_EVENT_ID, 'name' => "Pessoa {$id}", 'badge_name' => "P{$id}",
            'email' => "pessoa{$id}@teste.even3", 'gender' => 'M', 'photo' => '', 'document_number' => sprintf('%03d.%03d.%03d-%02d', $id, $id, $id, $id % 100),
            'check_in_code' => 5000 + $id, 'is_confirmed' => true, 'registration_category' => 'Participante',
            'registration_date' => '22/10/2026', 'registration_hour' => '13:10',
        ], $overrides);
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    protected function page(array $items, int $page = 1, int $totalPages = 1): array
    {
        return ['data' => $items, 'count' => count($items), 'pagination' => [
            'current_page' => $page, 'total_page' => $totalPages, 'page_size' => 100, 'quantity' => count($items),
            'has_previous' => $page > 1, 'has_next' => $page < $totalPages,
        ]];
    }

    /**
     * Fake completo: evento, entradas e participantes (uma página).
     *
     * @param  list<array<string, mixed>>  $tickets
     * @param  list<array<string, mixed>>  $attendees
     */
    protected function fakeEven3(array $tickets = [], array $attendees = [], string $eventId = self::EXTERNAL_EVENT_ID): void
    {
        Http::fake([
            self::EVEN3_BASE.'/events/'.$eventId.'/tickets' => Http::response($tickets),
            self::EVEN3_BASE.'/events/'.$eventId => Http::response($this->externalEvent($eventId)),
            self::EVEN3_BASE.'/events*' => Http::response($this->page([$this->externalEvent($eventId)])),
            self::EVEN3_BASE.'/attendees*' => Http::response($this->page($attendees)),
        ]);
    }

    protected function even3Integration(Event $event, string $externalId = self::EXTERNAL_EVENT_ID): EventIntegration
    {
        $integration = new EventIntegration(['event_id' => $event->id, 'provider' => 'EVEN3', 'external_event_id' => $externalId, 'api_version' => 'V2']);
        $integration->forceFill(['status' => IntegrationStatus::Active])->save();

        return $integration->refresh();
    }

    protected function ticketType(EventIntegration $integration, string $externalId, string $name, ?TicketCategory $category = null): ExternalTicketType
    {
        $ticket = ExternalTicketType::query()->create([
            'event_id' => $integration->event_id, 'event_integration_id' => $integration->id,
            'external_ticket_id' => $externalId, 'external_name' => $name, 'active' => true,
        ]);
        $ticket->forceFill(['internal_category' => $category])->save();

        return $ticket;
    }

    /**
     * Payload de webhook no formato documentado ("Gatilhos de participantes").
     *
     * @return array<string, mixed>
     */
    protected function webhookPayload(string $deliveryId, string $typeName, array $person = [], array $registration = [], string $eventId = self::EXTERNAL_EVENT_ID, ?int $created = null, ?int $typeId = null): array
    {
        return [
            'id' => $deliveryId,
            'created' => $created ?? 1793880000, // 2026-11-05T12:00:00Z
            'type' => ['id' => $typeId, 'name' => $typeName],
            'pessoa' => array_replace(['id' => 111111, 'nome' => 'Fulano Webhook', 'email' => 'fulano@teste.even3', 'cpf' => '', 'passaporte' => '', 'nome_para_cracha' => '', 'celular' => '', 'telefone_fixo' => ''], $person),
            'inscricao' => array_replace(['id' => 12345, 'codigo' => '12345', 'titulo' => 'Participante', 'data_inscricao' => '2026-10-22T13:26:00'], $registration),
            'evento' => ['id' => (int) $eventId, 'url' => 'hackathon-teste', 'titulo' => 'Qualquer título', 'data' => '2026-11-04T00:00:00'],
        ];
    }

    protected function postWebhook(array $payload, ?string $secret = self::EVEN3_WEBHOOK_SECRET, string $header = self::EVEN3_WEBHOOK_HEADER)
    {
        $headers = $secret === null ? [] : [$header => $secret];

        return $this->withHeaders($headers)->postJson('/api/v1/webhooks/even3', $payload);
    }
}
