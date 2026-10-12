<?php

namespace Tests\Feature\Integrations;

use App\Integrations\Even3\Even3Client;
use App\Integrations\Even3\Even3Exception;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\Concerns\Even3Fakes;
use Tests\TestCase;

/**
 * Even3Client (V2): Bearer, base URL, formatos, paginação, erros tipados, retry seguro e redação do token.
 */
class Even3ClientTest extends TestCase
{
    use Even3Fakes;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureEven3();
    }

    private function client(): Even3Client
    {
        return app(Even3Client::class);
    }

    private function expectCode(string $code, callable $call): Even3Exception
    {
        try {
            $call();
        } catch (Even3Exception $e) {
            $this->assertSame($code, $e->errorCode);
            $this->assertStringNotContainsString(self::EVEN3_TOKEN, $e->getMessage());
            $this->assertStringNotContainsString(self::EVEN3_TOKEN, (string) $e);

            return $e;
        }

        $this->fail("Esperava Even3Exception {$code}.");
    }

    public function test_bearer_and_base_url_for_event_tickets_and_attendee(): void
    {
        Http::fake([
            self::EVEN3_BASE.'/events/793572/tickets' => Http::response([$this->externalTicket(7, 'Participante')]),
            self::EVEN3_BASE.'/events/793572' => Http::response($this->externalEvent()),
            self::EVEN3_BASE.'/attendees/10' => Http::response($this->externalAttendee(10)),
        ]);

        $this->assertSame(793572, $this->client()->getEvent('793572')['id']);
        $this->assertSame(7, $this->client()->listTickets('793572')[0]['prices'][0]['ticket_id']);
        $this->assertSame(10, $this->client()->getAttendee('10')['id']);

        Http::assertSentCount(3);
        Http::assertSent(fn (Request $r) => $r->method() === 'GET'
            && str_starts_with($r->url(), self::EVEN3_BASE.'/')
            && $r->header('Authorization') === ['Bearer '.self::EVEN3_TOKEN]
            && $r->header('Accept') === ['application/json']);
    }

    public function test_attendees_follow_has_next_with_max_page_size(): void
    {
        Http::fake([
            self::EVEN3_BASE.'/attendees?page=1&page_size=100' => Http::response($this->page([$this->externalAttendee(1), $this->externalAttendee(2)], 1, 3)),
            self::EVEN3_BASE.'/attendees?page=2&page_size=100' => Http::response($this->page([$this->externalAttendee(3)], 2, 3)),
            self::EVEN3_BASE.'/attendees?page=3&page_size=100' => Http::response($this->page([$this->externalAttendee(4)], 3, 3)),
        ]);

        $ids = [];
        foreach ($this->client()->attendeePages() as $page) {
            array_push($ids, ...array_column($page['data'], 'id'));
        }

        $this->assertSame([1, 2, 3, 4], $ids);
        Http::assertSentCount(3);
    }

    public function test_pagination_has_a_safety_limit(): void
    {
        config(['services.even3.max_pages' => 2]);
        Http::fake([self::EVEN3_BASE.'/attendees*' => Http::response($this->page([$this->externalAttendee(1)], 1, 99))]);

        $this->expectCode(Even3Exception::INVALID_JSON, fn () => iterator_to_array($this->client()->attendeePages()));
    }

    public function test_typed_http_errors(): void
    {
        foreach ([400 => Even3Exception::BAD_REQUEST, 401 => Even3Exception::UNAUTHORIZED, 403 => Even3Exception::FORBIDDEN, 404 => Even3Exception::NOT_FOUND] as $status => $code) {
            Http::fake([self::EVEN3_BASE.'/events/'.$status => Http::response(['success' => false, 'message' => 'Erro', 'detail' => 'Detalhe '.self::EVEN3_TOKEN, 'code' => 'X'], $status)]);
            $e = $this->expectCode($code, fn () => $this->client()->getEvent((string) $status));
            $this->assertSame($status, $e->httpStatus);
        }
    }

    public function test_429_and_5xx_are_retried_then_reported(): void
    {
        Http::fake([
            self::EVEN3_BASE.'/events/1' => Http::sequence()->push(['message' => 'lento'], 429)->push(['message' => 'lento'], 429)->push(['message' => 'lento'], 429),
            self::EVEN3_BASE.'/events/2' => Http::sequence()->push(['message' => 'falha'], 500)->push($this->externalEvent('2')),
        ]);

        $this->expectCode(Even3Exception::RATE_LIMITED, fn () => $this->client()->getEvent('1'));
        $this->assertSame(2, $this->client()->getEvent('2')['id']); // 500 → retry → 200
        Http::assertSentCount(5); // 3 tentativas para /1 (1 + 2 retries) e 2 para /2
    }

    public function test_4xx_is_not_retried(): void
    {
        Http::fake([self::EVEN3_BASE.'/events/9' => Http::response(['message' => 'não'], 404)]);

        $this->expectCode(Even3Exception::NOT_FOUND, fn () => $this->client()->getEvent('9'));
        Http::assertSentCount(1);
    }

    public function test_server_error_after_retries(): void
    {
        Http::fake([self::EVEN3_BASE.'/events/5' => Http::response('', 503)]);

        $this->expectCode(Even3Exception::SERVER_ERROR, fn () => $this->client()->getEvent('5'));
        Http::assertSentCount(3);
    }

    public function test_timeout_and_network_failure_never_leak_the_token(): void
    {
        Http::fake([self::EVEN3_BASE.'/events/1' => fn () => throw new ConnectionException('cURL error 28: Operation timed out after 15000 ms Bearer '.self::EVEN3_TOKEN)]);
        $this->expectCode(Even3Exception::TIMEOUT, fn () => $this->client()->getEvent('1'));

        Http::fake([self::EVEN3_BASE.'/events/2' => fn () => throw new ConnectionException('cURL error 6: Could not resolve host '.self::EVEN3_TOKEN)]);
        $this->expectCode(Even3Exception::NETWORK_ERROR, fn () => $this->client()->getEvent('2'));
    }

    public function test_invalid_json(): void
    {
        Http::fake([
            self::EVEN3_BASE.'/events/1' => Http::response('<html>manutenção</html>', 200),
            self::EVEN3_BASE.'/events/2/tickets' => Http::response(['data' => []]),
            self::EVEN3_BASE.'/attendees*' => Http::response(['sem' => 'data']),
        ]);

        $this->expectCode(Even3Exception::INVALID_JSON, fn () => $this->client()->getEvent('1'));
        $this->expectCode(Even3Exception::INVALID_JSON, fn () => $this->client()->listTickets('2'));
        $this->expectCode(Even3Exception::INVALID_JSON, fn () => iterator_to_array($this->client()->attendeePages()));
    }

    public function test_missing_token_is_not_configured_and_sends_nothing(): void
    {
        config(['services.even3.token' => null]);

        $this->expectCode(Even3Exception::NOT_CONFIGURED, fn () => $this->client()->getEvent('1'));
        Http::assertNothingSent();
    }

    public function test_client_never_logs_the_token(): void
    {
        Log::spy();
        Http::fake([self::EVEN3_BASE.'/*' => Http::response(['detail' => self::EVEN3_TOKEN], 401)]);

        $this->expectCode(Even3Exception::UNAUTHORIZED, fn () => $this->client()->getEvent('1'));

        Log::shouldNotHaveReceived('error');
        Log::shouldNotHaveReceived('info');
        Log::shouldNotHaveReceived('warning');
    }
}
