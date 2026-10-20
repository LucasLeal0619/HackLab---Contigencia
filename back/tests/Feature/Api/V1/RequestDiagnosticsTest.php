<?php

namespace Tests\Feature\Api\V1;

use Tests\TestCase;

/**
 * Diagnóstico temporário da cadeia de proxies (SPEC HACKLAB-IP-001): desligado por padrão, só com o token,
 * e nunca devolve cookies, Authorization, CSRF, o token nem o valor do x-nf-sign.
 */
class RequestDiagnosticsTest extends TestCase
{
    private const URL = '/api/v1/system/request-diagnostics';

    public function test_is_not_found_when_disabled(): void
    {
        config(['app.request_diagnostics_token' => null]);

        $this->getJson(self::URL, ['X-Diagnostics-Token' => ''])->assertNotFound();
    }

    public function test_is_not_found_with_a_wrong_or_missing_token(): void
    {
        config(['app.request_diagnostics_token' => 'segredo-de-teste']);

        $this->getJson(self::URL)->assertNotFound();
        $this->getJson(self::URL, ['X-Diagnostics-Token' => 'outro'])->assertNotFound();
    }

    public function test_is_rate_limited_even_with_wrong_tokens(): void
    {
        config(['app.request_diagnostics_token' => 'segredo-de-teste']);

        foreach (range(1, 30) as $attempt) {
            $this->getJson(self::URL, ['X-Diagnostics-Token' => "tentativa-{$attempt}"])->assertNotFound();
        }

        $this->getJson(self::URL, ['X-Diagnostics-Token' => 'segredo-de-teste'])->assertStatus(429);
    }

    public function test_shows_the_proxy_chain_without_secrets(): void
    {
        config(['app.request_diagnostics_token' => 'segredo-de-teste']);

        $response = $this->withServerVariables(['REMOTE_ADDR' => '10.27.0.14'])
            ->getJson(self::URL, [
                'X-Diagnostics-Token' => 'segredo-de-teste',
                'X-Forwarded-For' => '203.0.113.7, 198.51.100.20',
                'X-Nf-Client-Connection-Ip' => '2001:db8::7',
                'X-Nf-Sign' => 'jws-que-nao-pode-vazar',
                'Authorization' => 'Bearer nao-pode-vazar',
                'Cookie' => 'hacklab-session=nao-pode-vazar',
            ])
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.remote_addr', '10.27.0.14')
            ->assertJsonPath('data.headers.x-forwarded-for', ['203.0.113.7, 198.51.100.20'])
            ->assertJsonPath('data.headers.x-nf-client-connection-ip', ['2001:db8::7'])
            ->assertJsonPath('data.x_nf_sign_present', true);

        $body = $response->getContent();
        foreach (['segredo-de-teste', 'jws-que-nao-pode-vazar', 'nao-pode-vazar'] as $secret) {
            $this->assertStringNotContainsString($secret, $body);
        }
        $this->assertNotContains('cookie', $response->json('data.header_names'));
        $this->assertNotContains('authorization', $response->json('data.header_names'));
        $this->assertContains('x-nf-sign', $response->json('data.header_names'));
    }
}
