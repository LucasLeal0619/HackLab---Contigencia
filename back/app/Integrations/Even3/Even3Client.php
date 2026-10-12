<?php

namespace App\Integrations\Even3;

use Generator;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Único ponto de HTTP com a Even3 (API V2). Centraliza Bearer, base URL, timeouts, retry de GET,
 * paginação e erros tipados. O token nunca aparece em mensagens: toda mensagem passa por redact().
 *
 * Só leitura nesta fase (o HackLab não escreve na Even3).
 */
class Even3Client
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly ?string $token,
        private readonly int $timeout = 15,
        private readonly int $connectTimeout = 5,
        private readonly int $retries = 2,
        private readonly int $retryBackoffMs = 500,
        private readonly int $pageSize = 100,
        private readonly int $maxPages = 500,
    ) {}

    public static function fromConfig(): self
    {
        $config = config('services.even3');

        return new self(
            baseUrl: rtrim((string) $config['base_url'], '/'),
            token: $config['token'] ?: null,
            timeout: (int) $config['timeout'],
            connectTimeout: (int) $config['connect_timeout'],
            retries: (int) $config['retries'],
            retryBackoffMs: (int) $config['retry_backoff_ms'],
            pageSize: (int) $config['page_size'],
            maxPages: (int) $config['max_pages'],
        );
    }

    public function baseUrl(): string
    {
        return $this->baseUrl;
    }

    public function hasToken(): bool
    {
        return $this->token !== null && $this->token !== '';
    }

    /**
     * Eventos acessíveis pela chave (todas as páginas).
     *
     * @return list<array<string, mixed>>
     */
    public function listEvents(): array
    {
        $events = [];

        foreach ($this->pages('/events') as $page) {
            array_push($events, ...$page['data']);
        }

        return $events;
    }

    /**
     * @return array<string, mixed>
     */
    public function getEvent(string $externalEventId): array
    {
        return $this->object($this->get('/events/'.rawurlencode($externalEventId)), 'evento');
    }

    /**
     * Entradas do evento (a V2 devolve lista pura, sem envelope).
     *
     * @return list<array<string, mixed>>
     */
    public function listTickets(string $externalEventId): array
    {
        $body = $this->get('/events/'.rawurlencode($externalEventId).'/tickets');

        if (! is_array($body) || ! array_is_list($body)) {
            throw new Even3Exception(Even3Exception::INVALID_JSON, 'Resposta inválida da Even3 em /tickets: lista esperada.');
        }

        return $body;
    }

    /**
     * Participantes, página a página (has_next), com teto de segurança de páginas.
     *
     * @return Generator<int, array{data: list<array<string, mixed>>, pagination: array<string, mixed>}>
     */
    public function attendeePages(): Generator
    {
        yield from $this->pages('/attendees');
    }

    /**
     * @return array<string, mixed>
     */
    public function getAttendee(string $externalAttendeeId): array
    {
        return $this->object($this->get('/attendees/'.rawurlencode($externalAttendeeId)), 'participante');
    }

    /**
     * @return Generator<int, array{data: list<array<string, mixed>>, pagination: array<string, mixed>}>
     */
    private function pages(string $path): Generator
    {
        for ($page = 1; $page <= $this->maxPages; $page++) {
            $body = $this->get($path, ['page' => $page, 'page_size' => $this->pageSize]);

            if (! is_array($body) || ! is_array($body['data'] ?? null) || ! array_is_list($body['data'])) {
                throw new Even3Exception(Even3Exception::INVALID_JSON, "Resposta inválida da Even3 em {$path}: campo data ausente.");
            }

            $pagination = is_array($body['pagination'] ?? null) ? $body['pagination'] : [];

            yield ['data' => $body['data'], 'pagination' => $pagination];

            if (($pagination['has_next'] ?? false) !== true) {
                return;
            }
        }

        throw new Even3Exception(Even3Exception::INVALID_JSON, "Paginação da Even3 em {$path} excedeu {$this->maxPages} páginas.");
    }

    /**
     * GET com retry controlado (timeout, rede, 429, 5xx). 4xx não é repetido.
     *
     * @param  array<string, scalar>  $query
     */
    private function get(string $path, array $query = []): mixed
    {
        if (! $this->hasToken()) {
            throw new Even3Exception(Even3Exception::NOT_CONFIGURED, 'EVEN3_API_TOKEN não está definido no ambiente do backend.');
        }

        $attempt = 0;

        while (true) {
            try {
                return $this->decode($this->send($path, $query), $path);
            } catch (Even3Exception $e) {
                if (! $e->isRetryable() || $attempt >= $this->retries) {
                    throw $e;
                }

                $attempt++;
                usleep($this->retryBackoffMs * $attempt * 1000);
            }
        }
    }

    /**
     * @param  array<string, scalar>  $query
     */
    private function send(string $path, array $query): Response
    {
        try {
            return Http::withToken((string) $this->token)
                ->acceptJson()
                ->connectTimeout($this->connectTimeout)
                ->timeout($this->timeout)
                ->get($this->baseUrl.$path, $query);
        } catch (ConnectionException $e) {
            $timeout = str_contains(strtolower($e->getMessage()), 'timed out') || str_contains($e->getMessage(), 'cURL error 28');

            throw new Even3Exception(
                $timeout ? Even3Exception::TIMEOUT : Even3Exception::NETWORK_ERROR,
                ($timeout ? 'Tempo esgotado ao chamar a Even3' : 'Falha de rede ao chamar a Even3').": {$this->redact($e->getMessage())}",
            );
        } catch (Throwable $e) {
            throw new Even3Exception(Even3Exception::NETWORK_ERROR, 'Erro ao chamar a Even3: '.$this->redact($e->getMessage()));
        }
    }

    private function decode(Response $response, string $path): mixed
    {
        $status = $response->status();

        if ($response->successful()) {
            $body = json_decode($response->body(), true);

            if (! is_array($body)) {
                throw new Even3Exception(Even3Exception::INVALID_JSON, "Resposta não-JSON da Even3 em {$path}.", $status);
            }

            return $body;
        }

        $code = match (true) {
            $status === 400 => Even3Exception::BAD_REQUEST,
            $status === 401 => Even3Exception::UNAUTHORIZED,
            $status === 403 => Even3Exception::FORBIDDEN,
            $status === 404 => Even3Exception::NOT_FOUND,
            $status === 429 => Even3Exception::RATE_LIMITED,
            $status >= 500 => Even3Exception::SERVER_ERROR,
            default => Even3Exception::BAD_REQUEST,
        };

        $body = json_decode($response->body(), true);
        $detail = is_array($body) ? trim(($body['message'] ?? '').' — '.($body['detail'] ?? '').' ('.($body['code'] ?? '').')', ' —()') : '';

        throw new Even3Exception($code, $this->redact("Even3 respondeu {$status} em {$path}".($detail !== '' ? ": {$detail}" : '.')), $status);
    }

    /**
     * @return array<string, mixed>
     */
    private function object(mixed $body, string $what): array
    {
        if (! is_array($body) || array_is_list($body)) {
            throw new Even3Exception(Even3Exception::INVALID_JSON, "Resposta inválida da Even3: {$what} esperado como objeto.");
        }

        return $body;
    }

    public function redact(string $text): string
    {
        return $this->hasToken() ? str_replace((string) $this->token, '[token oculto]', $text) : $text;
    }
}
