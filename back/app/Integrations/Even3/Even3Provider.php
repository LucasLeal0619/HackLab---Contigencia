<?php

namespace App\Integrations\Even3;

use App\Integrations\EventPlatform\EventPlatformProvider;

/**
 * Even3 (API V2) atrás do contrato neutro. Erros chegam como Even3Exception (mensagem segura).
 */
class Even3Provider implements EventPlatformProvider
{
    public const NAME = 'EVEN3';

    public function __construct(
        private readonly Even3Client $client,
        private readonly Even3Mapper $mapper,
    ) {}

    public function name(): string
    {
        return self::NAME;
    }

    public function fetchEvent(string $externalEventId): array
    {
        return $this->mapper->event($this->client->getEvent($externalEventId));
    }

    public function fetchTickets(string $externalEventId): array
    {
        return $this->mapper->tickets($this->client->listTickets($externalEventId));
    }

    public function fetchAttendees(): iterable
    {
        foreach ($this->client->attendeePages() as $page) {
            yield array_map(fn (array $attendee) => $this->mapper->attendee($attendee), $page['data']);
        }
    }

    public function accessibleEvents(): array
    {
        return array_map(fn (array $event) => $this->mapper->event($event), $this->client->listEvents());
    }
}
