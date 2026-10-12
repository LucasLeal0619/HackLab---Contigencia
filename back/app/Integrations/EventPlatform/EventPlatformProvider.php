<?php

namespace App\Integrations\EventPlatform;

/**
 * Contrato neutro da plataforma externa de inscrições. Os Services do HackLab só conhecem estes formatos;
 * outra plataforma (ex.: Sympla) = outra implementação, sem mudar o domínio.
 *
 * Formatos normalizados:
 * - evento: { external_event_id, title, start_date?, end_date?, city?, state? }
 * - entrada: { external_ticket_id, name }  (+ problemas: { problem, name })
 * - participante: { external_attendee_id, external_event_id, name, badge_name, email, document, phone,
 *                   check_in_code, confirmed (bool), category_label, registration_date, registration_hour }
 */
interface EventPlatformProvider
{
    public function name(): string;

    /**
     * @return array<string, mixed>
     */
    public function fetchEvent(string $externalEventId): array;

    /**
     * @return array{tickets: list<array{external_ticket_id: string, name: string}>, problems: list<array{problem: string, name: string}>}
     */
    public function fetchTickets(string $externalEventId): array;

    /**
     * Páginas de participantes normalizados.
     *
     * @return iterable<int, list<array<string, mixed>>>
     */
    public function fetchAttendees(): iterable;

    /**
     * Eventos acessíveis pela credencial.
     *
     * @return list<array<string, mixed>>
     */
    public function accessibleEvents(): array;
}
