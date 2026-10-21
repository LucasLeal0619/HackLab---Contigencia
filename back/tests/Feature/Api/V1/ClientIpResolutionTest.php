<?php

namespace Tests\Feature\Api\V1;

use App\Domain\Audit\AuditAction;
use App\Domain\Users\Enums\RoleCode;
use App\Models\AuditLog;
use App\Support\Http\NetlifyProxySignature;
use App\Support\Http\ProxyConfiguration;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Testing\TestResponse;
use Tests\DatabaseTestCase;

/**
 * HACKLAB-IP-001: IP real do cliente atrás de Netlify → Render, pelo stack HTTP completo (middlewares reais).
 * Cadeias e cabeçalhos copiados do diagnóstico de produção de 20/10/2026.
 */
class ClientIpResolutionTest extends DatabaseTestCase
{
    private const SECRET = 'segredo-netlify-de-teste';

    private const SITE = 'https://hacklab-senac.netlify.app';

    private const LOCAL_PROXY = '::1';

    private const CLIENT = '177.128.10.20';

    private const NETLIFY_EGRESS = '56.126.136.177';

    private int $attempt = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->behindRender();
    }

    protected function tearDown(): void
    {
        TrustProxies::flushState();

        parent::tearDown();
    }

    public function test_signed_netlify_request_uses_the_netlify_client_ip(): void
    {
        $this->assertSame(self::CLIENT, $this->auditedIp($this->viaNetlify()));
    }

    public function test_signed_netlify_request_supports_ipv6_clients(): void
    {
        $this->assertSame('2804:14c:65:8e00::1a2b', $this->auditedIp($this->viaNetlify(clientIp: '2804:14c:65:8e00::1a2b')));
    }

    public function test_unsigned_netlify_request_falls_back_to_the_ip_that_connected_to_render(): void
    {
        $headers = $this->viaNetlify();
        unset($headers['X-Nf-Sign']);

        $this->assertSame(self::NETLIFY_EGRESS, $this->auditedIp($headers));
    }

    public function test_invalid_signatures_are_rejected(): void
    {
        $cases = [
            'segredo errado' => $this->jws(secret: 'outro-segredo'),
            'vencida' => $this->jws(['exp' => time() - NetlifyProxySignature::LEEWAY_SECONDS - 1]),
            'sem exp' => $this->jws(['exp' => null]),
            'outro site' => $this->jws(['site_url' => 'https://atacante.netlify.app']),
            'outro emissor' => $this->jws(['iss' => 'atacante']),
            'deploy preview' => $this->jws(['deploy_context' => 'deploy-preview']),
            'alg none' => $this->jws(header: ['alg' => 'none', 'typ' => 'JWT']),
            'alg HS512' => $this->jws(header: ['alg' => 'HS512', 'typ' => 'JWT']),
            'typ estranho' => $this->jws(header: ['alg' => 'HS256', 'typ' => 'XYZ']),
            'crit' => $this->jws(header: ['alg' => 'HS256', 'crit' => ['b64']]),
            'lixo' => 'nao.e.jws',
            'partes faltando' => 'abc.def',
        ];

        foreach ($cases as $label => $jws) {
            $this->assertSame(self::NETLIFY_EGRESS, $this->auditedIp($this->viaNetlify(jws: $jws)), $label);
        }
    }

    public function test_claims_altered_after_signing_are_rejected(): void
    {
        [$header, , $signature] = explode('.', $this->jws());
        $forgedClaims = NetlifyProxySignature::base64UrlEncode(json_encode([
            'iss' => 'netlify', 'exp' => time() + 3600, 'deploy_context' => 'production', 'site_url' => self::SITE, 'netlify_id' => 'outro',
        ]));

        $this->assertSame(self::NETLIFY_EGRESS, $this->auditedIp($this->viaNetlify(jws: "{$header}.{$forgedClaims}.{$signature}")));
    }

    public function test_expiry_tolerates_only_the_clock_leeway(): void
    {
        $this->assertSame(self::CLIENT, $this->auditedIp($this->viaNetlify(jws: $this->jws(['exp' => time() - 5]))));
    }

    public function test_direct_request_to_render_uses_cf_connecting_ip_and_ignores_forged_headers(): void
    {
        $ip = $this->auditedIp([
            'X-Forwarded-For' => '198.51.100.66,'.self::CLIENT.', 172.71.238.232, 10.26.16.133',
            'CF-Connecting-IP' => self::CLIENT,
            'True-Client-IP' => self::CLIENT,
            'X-Real-IP' => '198.51.100.67',
            'Forwarded' => 'for=198.51.100.71',
            // Direto no Render, o x-nf-client-connection-ip chega intacto: sem assinatura, não vale.
            'X-Nf-Client-Connection-Ip' => '198.51.100.69',
        ]);

        $this->assertSame(self::CLIENT, $ip);
    }

    public function test_signed_request_with_invalid_client_ip_falls_back_to_the_edge_ip(): void
    {
        foreach (['nao-e-ip', '177.128.10.20, 198.51.100.1', '177.128.10.20:443', '', '999.1.1.1'] as $value) {
            $this->assertSame(self::NETLIFY_EGRESS, $this->auditedIp($this->viaNetlify(clientIp: $value)), $value);
        }
    }

    public function test_without_any_authenticated_source_the_ip_is_the_local_proxy_never_a_header(): void
    {
        $this->assertSame(self::LOCAL_PROXY, $this->auditedIp(['X-Forwarded-For' => '198.51.100.66, 10.26.16.133']));
        $this->assertSame(self::LOCAL_PROXY, $this->auditedIp(['CF-Connecting-IP' => '1.2.3.4, 5.6.7.8']));
    }

    public function test_requests_not_coming_from_the_local_proxy_are_not_rewritten(): void
    {
        $headers = $this->viaNetlify();

        $this->assertSame('203.0.113.250', $this->auditedIp($headers, remote: '203.0.113.250'));
    }

    public function test_without_trusted_proxies_forwarded_headers_are_ignored(): void
    {
        config(['app.trusted_proxies' => null]);
        TrustProxies::flushState();
        ProxyConfiguration::apply(null, 'testing', null);

        $this->assertSame('203.0.113.5', $this->auditedIp($this->viaNetlify(), remote: '203.0.113.5'));
    }

    public function test_wildcard_keeps_the_legacy_behaviour_and_does_not_enable_header_resolution(): void
    {
        config(['app.trusted_proxies' => '*']);
        TrustProxies::flushState();
        ProxyConfiguration::apply('*', 'testing', null);

        // Comportamento anterior (último salto do X-Forwarded-For): o deploy do código sem trocar a variável não muda nada.
        $this->assertSame('10.27.0.14', $this->auditedIp($this->viaNetlify()));
    }

    public function test_successful_login_and_logout_audit_the_client_ip(): void
    {
        $user = $this->userWithRole(RoleCode::Manager, ['email' => 'gestor@hacklab.test']);
        $headers = $this->viaNetlify();

        $this->proxied($headers)->postJson('/api/v1/auth/login', ['email' => 'gestor@hacklab.test', 'password' => 'password1'])->assertOk();
        $this->proxied($headers)->postJson('/api/v1/auth/logout')->assertNoContent();

        $this->assertSame(self::CLIENT, AuditLog::query()->where('action', AuditAction::LOGIN)->where('entity_id', (string) $user->id)->value('ip_address'));
        $this->assertSame(self::CLIENT, AuditLog::query()->where('action', AuditAction::LOGOUT)->where('entity_id', (string) $user->id)->value('ip_address'));
    }

    public function test_session_cookie_from_a_proxied_login_keeps_the_user_authenticated(): void
    {
        $this->userWithRole(RoleCode::Manager, ['email' => 'gestor@hacklab.test']);
        $headers = $this->viaNetlify();

        $this->proxied($headers)->get('/sanctum/csrf-cookie')->assertNoContent()->assertCookie('XSRF-TOKEN');
        $login = $this->proxied($headers)->postJson('/api/v1/auth/login', ['email' => 'gestor@hacklab.test', 'password' => 'password1'])->assertOk();
        $session = $login->getCookie(config('session.cookie'));
        $this->assertNotNull($session);

        // Requisição seguinte só com o cookie de sessão (sem o usuário em memória).
        $this->app['auth']->forgetGuards();
        $this->proxied($headers)->withCookie(config('session.cookie'), $session->getValue())
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.email', 'gestor@hacklab.test');
    }

    public function test_login_rate_limit_is_per_real_client_and_cannot_be_reset_with_forged_headers(): void
    {
        foreach (range(1, 5) as $i) {
            $this->login($this->viaNetlify(forgedFor: "198.51.100.{$i}"), 'alvo@hacklab.test');
        }

        // Mesmo cliente real, outro X-Forwarded-For forjado: continua bloqueado.
        $this->login($this->viaNetlify(forgedFor: '198.51.100.99'), 'alvo@hacklab.test')->assertStatus(429);
        // Outro cliente real: limite próprio.
        $this->login($this->viaNetlify(clientIp: '177.128.10.21'), 'alvo@hacklab.test')->assertUnprocessable();
    }

    public function test_forwarded_host_is_never_trusted_for_absolute_urls(): void
    {
        $this->actingAs($this->admin())->withServerVariables(['REMOTE_ADDR' => self::LOCAL_PROXY])
            ->withHeaders(['X-Forwarded-Host' => 'evil.example', 'X-Forwarded-Proto' => 'https'] + $this->viaNetlify())
            ->getJson('/api/v1/people')
            ->assertOk()
            ->assertJsonPath('links.first', fn (string $url) => ! str_contains($url, 'evil.example'));
    }

    public function test_production_absolute_urls_come_from_app_url_not_from_the_host_header(): void
    {
        ProxyConfiguration::apply('127.0.0.1,::1', 'production', 'https://api.hacklab.test');

        $this->actingAs($this->admin())->withServerVariables(['REMOTE_ADDR' => self::LOCAL_PROXY])
            ->getJson('http://evil.example/api/v1/people')
            ->assertOk()
            ->assertJsonPath('links.first', fn (string $url) => str_starts_with($url, 'https://api.hacklab.test/api/v1/people'));
    }

    /**
     * Configuração de produção (Render), mas com valores de teste.
     */
    private function behindRender(): void
    {
        config([
            'app.trusted_proxies' => '127.0.0.1,::1',
            'app.edge_client_ip_header' => 'CF-Connecting-IP',
            'app.netlify_proxy_secret' => self::SECRET,
            'app.frontend_url' => self::SITE,
        ]);
        TrustProxies::flushState();
        ProxyConfiguration::apply('127.0.0.1,::1', 'testing', null);
    }

    /**
     * Cabeçalhos como chegam ao Laravel pelo caminho Netlify → Cloudflare → Render.
     *
     * @return array<string, string>
     */
    private function viaNetlify(string $clientIp = self::CLIENT, ?string $jws = null, ?string $forgedFor = null): array
    {
        $chain = ($forgedFor ? "{$forgedFor}, " : '').$clientIp.', 100.64.0.244,'.self::NETLIFY_EGRESS.', 172.68.19.244, 10.27.0.14';

        return [
            'X-Forwarded-For' => $chain,
            'X-Forwarded-Proto' => 'https',
            'CF-Connecting-IP' => self::NETLIFY_EGRESS,
            'True-Client-IP' => self::NETLIFY_EGRESS,
            'X-Nf-Client-Connection-Ip' => $clientIp,
            'X-Nf-Sign' => $jws ?? $this->jws(),
        ];
    }

    /**
     * @param  array<string, mixed>  $claims  valores null removem a claim
     * @param  array<string, mixed>  $header
     */
    private function jws(array $claims = [], string $secret = self::SECRET, array $header = ['alg' => 'HS256', 'typ' => 'JWT']): string
    {
        $claims = array_filter($claims + [
            'deploy_context' => 'production',
            'exp' => time() + 60,
            'iss' => 'netlify',
            'netlify_id' => '1be0f471-6532-45ff-b1b1-f9c66ea24dc1',
            'site_url' => self::SITE,
        ], fn ($value) => $value !== null);

        $signingInput = NetlifyProxySignature::base64UrlEncode(json_encode($header)).'.'.NetlifyProxySignature::base64UrlEncode(json_encode($claims));

        return $signingInput.'.'.NetlifyProxySignature::base64UrlEncode(hash_hmac('sha256', $signingInput, $secret, true));
    }

    private function proxied(array $headers, string $remote = self::LOCAL_PROXY): static
    {
        return $this->fromSpa()->withServerVariables(['REMOTE_ADDR' => $remote])->withHeaders($headers);
    }

    private function login(array $headers, string $email, string $remote = self::LOCAL_PROXY): TestResponse
    {
        return $this->proxied($headers, $remote)->postJson('/api/v1/auth/login', ['email' => $email, 'password' => 'errada']);
    }

    /**
     * IP gravado na Auditoria por uma tentativa de login recusada (e-mail único: não esbarra no rate limit).
     */
    private function auditedIp(array $headers, string $remote = self::LOCAL_PROXY): ?string
    {
        $email = 'ip'.(++$this->attempt).'@hacklab.test';
        $this->login($headers, $email, $remote)->assertUnprocessable();
        $this->flushHeaders();

        return AuditLog::query()->where('action', AuditAction::LOGIN_FAILED)->orderByDesc('id')->value('ip_address');
    }
}
