<?php

namespace Tests\Feature\Api\V1;

use App\Providers\AppServiceProvider;
use Illuminate\Http\Middleware\TrustProxies;
use Tests\DatabaseTestCase;

/**
 * Produção atrás do proxy do Render: com TRUSTED_PROXIES os rate limits contam o IP real do cliente
 * (X-Forwarded-For); sem ele (desenvolvimento) o cabeçalho é ignorado.
 */
class TrustedProxiesTest extends DatabaseTestCase
{
    protected function tearDown(): void
    {
        TrustProxies::flushState();

        parent::tearDown();
    }

    public function test_without_trusted_proxies_forwarded_for_is_ignored(): void
    {
        $this->exhaustLoginFrom('203.0.113.10');

        // Outro X-Forwarded-For não muda nada: o IP é o da conexão.
        $this->login('203.0.113.20')->assertStatus(429);
    }

    public function test_behind_a_trusted_proxy_each_client_ip_has_its_own_limit(): void
    {
        config(['app.trusted_proxies' => '*']);
        $this->app->getProvider(AppServiceProvider::class)->boot();

        $this->exhaustLoginFrom('203.0.113.10');

        $this->login('203.0.113.10')->assertStatus(429);
        $this->login('203.0.113.20')->assertUnprocessable();
    }

    private function exhaustLoginFrom(string $ip): void
    {
        foreach (range(1, 5) as $attempt) {
            $this->login($ip);
        }
    }

    private function login(string $ip)
    {
        return $this->fromSpa()->withHeader('X-Forwarded-For', $ip)
            ->postJson('/api/v1/auth/login', ['email' => 'alvo@hacklab.test', 'password' => 'errada']);
    }
}
