<?php

namespace App\Console\Commands;

use App\Integrations\Even3\Even3Client;
use App\Integrations\Even3\Even3Exception;
use App\Integrations\Even3\Even3Mapper;
use App\Models\EventIntegration;
use Illuminate\Console\Command;

/**
 * Teste de conectividade com a Even3 (API V2, Authorization: Bearer). Somente leitura:
 * não grava no banco, não sincroniza, não cria webhook. Nunca mostra o token nem dados pessoais
 * (dos participantes mostra só contagens, ids e quais campos vieram preenchidos).
 *
 * Sem fallback para a V1: se a V2 recusar a chave, o comando falha.
 */
class Even3TestConnection extends Command
{
    protected $signature = 'even3:test-connection {--event= : external_event_id a conferir (padrão: os das integrações configuradas)}';

    protected $description = 'Testa a credencial da Even3 na API V2 (eventos, entradas e participantes; somente leitura)';

    public function handle(Even3Client $client, Even3Mapper $mapper): int
    {
        if (! $client->hasToken()) {
            $this->error('EVEN3_API_TOKEN não está definido no ambiente do backend.');

            return self::FAILURE;
        }

        $this->line("API: {$client->baseUrl()} (V2, Bearer)");

        try {
            $events = $client->listEvents();
        } catch (Even3Exception $e) {
            return $this->reportFailure($e);
        }

        $this->info('Autenticação OK. Eventos acessíveis pela chave: '.count($events));
        $this->table(['id', 'title', 'start_date', 'end_date'], array_map(fn ($e) => [$e['id'] ?? '-', $e['title'] ?? '-', $e['start_date'] ?? '-', $e['end_date'] ?? '-'], $events));

        $targets = $this->option('event')
            ? [(string) $this->option('event')]
            : EventIntegration::query()->where('provider', 'EVEN3')->pluck('external_event_id')->all();

        foreach ($targets as $externalId) {
            if (! in_array($externalId, array_map(fn ($e) => (string) ($e['id'] ?? ''), $events), true)) {
                $this->error("Evento externo {$externalId} NÃO está acessível por esta chave.");

                return self::FAILURE;
            }

            $this->info("Evento externo {$externalId} acessível.");

            try {
                $tickets = $client->listTickets($externalId);
                $normalized = $mapper->tickets($tickets);
                $this->line('Entradas: '.count($tickets).' (com ticket_id: '.count($normalized['tickets']).', com problema: '.count($normalized['problems']).')');

                foreach ($tickets as $ticket) {
                    $prices = is_array($ticket['prices'] ?? null) ? $ticket['prices'] : [];
                    $this->line(sprintf(
                        '  - "%s": lotes=%d, ticket_id(s)=%s, ids de lote=%s',
                        $ticket['title'] ?? '?',
                        count($prices),
                        implode(',', array_unique(array_map(fn ($p) => (string) ($p['ticket_id'] ?? 'null'), $prices))) ?: '-',
                        implode(',', array_map(fn ($p) => (string) ($p['id'] ?? 'null'), $prices)) ?: '-',
                    ));
                }
            } catch (Even3Exception $e) {
                return $this->reportFailure($e);
            }
        }

        try {
            $total = 0;
            $pages = 0;
            $fields = [];
            $byEvent = [];
            $categories = [];

            foreach ($client->attendeePages() as $page) {
                $pages++;
                foreach ($page['data'] as $attendee) {
                    $total++;
                    $byEvent[(string) ($attendee['event_id'] ?? '?')] = ($byEvent[(string) ($attendee['event_id'] ?? '?')] ?? 0) + 1;
                    $categories[(string) ($attendee['registration_category'] ?? '')] = ($categories[(string) ($attendee['registration_category'] ?? '')] ?? 0) + 1;

                    foreach ($attendee as $key => $value) {
                        $filled = $value !== null && $value !== '' && $value !== [];
                        $fields[$key] = ($fields[$key] ?? 0) + ($filled ? 1 : 0);
                    }
                }
                $this->line("Participantes: página {$page['pagination']['current_page']} de {$page['pagination']['total_page']} (page_size {$page['pagination']['page_size']}, has_next ".json_encode($page['pagination']['has_next'] ?? null).')');
            }

            $this->line("Participantes: {$total} em {$pages} página(s). Por event_id: ".json_encode($byEvent));
            $this->line('Categorias (registration_category): '.json_encode(array_map(fn ($k) => $k === '' ? '(vazio)' : $k, array_keys($categories)), JSON_UNESCAPED_UNICODE).' contagens '.json_encode(array_values($categories)));
            $this->line('Campos preenchidos (quantos participantes têm o campo não vazio): '.json_encode($fields));
        } catch (Even3Exception $e) {
            return $this->reportFailure($e);
        }

        return self::SUCCESS;
    }

    private function reportFailure(Even3Exception $e): int
    {
        $hint = match ($e->errorCode) {
            Even3Exception::UNAUTHORIZED, Even3Exception::FORBIDDEN => ' Autenticação recusada na V2: confira o EVEN3_API_TOKEN (chave do evento, em Ferramentas → Integrações → API Even3). Não há fallback para a V1.',
            Even3Exception::TIMEOUT, Even3Exception::NETWORK_ERROR => ' Sem resposta da Even3 (timeout ou falha de conexão).',
            default => '',
        };

        $this->error("[{$e->errorCode}".($e->httpStatus ? " HTTP {$e->httpStatus}" : '')."] {$e->getMessage()}{$hint}");

        return self::FAILURE;
    }
}
