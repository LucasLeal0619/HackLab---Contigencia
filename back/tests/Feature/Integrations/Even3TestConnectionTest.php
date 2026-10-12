<?php

namespace Tests\Feature\Integrations;

use App\Models\AuditLog;
use App\Models\Person;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\Even3Fakes;
use Tests\DatabaseTestCase;

/**
 * even3:test-connection (API V2, Bearer): somente leitura, sem gravar nada, sem PII de participantes,
 * sem nunca exibir o token e sem fallback para a V1.
 */
class Even3TestConnectionTest extends DatabaseTestCase
{
    use Even3Fakes;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureEven3();
    }

    /**
     * @return array{0: int, 1: string}
     */
    private function runCommand(string $options = '--event=793572'): array
    {
        $exit = Artisan::call('even3:test-connection '.$options);

        return [$exit, Artisan::output()];
    }

    public function test_success_checks_event_tickets_and_attendees_without_pii(): void
    {
        $this->fakeEven3(
            [$this->externalTicket(555, 'Participante', lots: 2)],
            [$this->externalAttendee(1, ['name' => 'Nome Sigiloso', 'email' => 'sigilo@teste.even3'])],
        );

        [$exit, $output] = $this->runCommand();

        $this->assertSame(0, $exit, $output);
        $this->assertStringContainsString('Autenticação OK. Eventos acessíveis pela chave: 1', $output);
        $this->assertStringContainsString('Evento externo 793572 acessível.', $output);
        $this->assertStringContainsString('Entradas: 1 (com ticket_id: 1', $output);
        $this->assertStringContainsString('ticket_id(s)=555', $output);
        $this->assertStringContainsString('Participantes: 1 em 1 página(s)', $output);
        $this->assertStringNotContainsString('Nome Sigiloso', $output);
        $this->assertStringNotContainsString('sigilo@teste.even3', $output);
        $this->assertStringNotContainsString(self::EVEN3_TOKEN, $output);

        Http::assertSent(fn (Request $r) => str_starts_with($r->url(), self::EVEN3_BASE.'/events')
            && $r->header('Authorization') === ['Bearer '.self::EVEN3_TOKEN]);
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'www.even3.com.br/api/v1'));
        $this->assertSame(0, AuditLog::query()->count());
        $this->assertSame(0, Person::query()->count(), 'O teste de conexão não grava nada.');
    }

    public function test_event_not_accessible_by_the_key_fails(): void
    {
        $this->fakeEven3(eventId: '111');

        [$exit, $output] = $this->runCommand('--event=793572');

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('NÃO está acessível', $output);
    }

    public function test_401_and_403_fail_clearly_without_v1_fallback(): void
    {
        Http::fake(['*' => Http::response(['success' => false, 'message' => 'Unauthorized', 'detail' => 'Invalid bearer token.', 'code' => 'InvalidAuthorizationToken'], 401)]);

        [$exit, $output] = $this->runCommand();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('[UNAUTHORIZED HTTP 401]', $output);
        $this->assertStringContainsString('Não há fallback para a V1', $output);
        $this->assertStringNotContainsString(self::EVEN3_TOKEN, $output);
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/api/v1'));
    }

    public function test_timeout_is_reported_without_the_token(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out ('.self::EVEN3_TOKEN.')'));

        [$exit, $output] = $this->runCommand();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('[TIMEOUT]', $output);
        $this->assertStringNotContainsString(self::EVEN3_TOKEN, $output);
    }

    public function test_missing_token_fails_before_any_request(): void
    {
        config(['services.even3.token' => null]);

        [$exit, $output] = $this->runCommand();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('EVEN3_API_TOKEN não está definido', $output);
        Http::assertNothingSent();
    }
}
