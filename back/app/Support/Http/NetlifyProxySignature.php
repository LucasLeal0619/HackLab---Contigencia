<?php

namespace App\Support\Http;

/**
 * Valida o x-nf-sign do proxy assinado do Netlify (JWS compacto, HMAC HS256 com o segredo do site).
 *
 * A assinatura cobre só as claims do próprio JWS (iss, exp, site_url, deploy_context, netlify_id), não os
 * cabeçalhos HTTP: ela prova que a requisição passou pelo proxy do nosso site no Netlify. A confiança no
 * x-nf-client-connection-ip vem de o Netlify sobrescrevê-lo nesse caminho (verificado em produção em 20/10/2026).
 */
class NetlifyProxySignature
{
    /** Tolerância de relógio para o exp, em segundos. */
    public const LEEWAY_SECONDS = 30;

    public function __construct(
        private readonly string $secret,
        private readonly string $siteUrl,
    ) {}

    public static function fromConfig(): self
    {
        return new self((string) config('app.netlify_proxy_secret'), (string) config('app.frontend_url'));
    }

    public function isConfigured(): bool
    {
        return $this->secret !== '' && $this->normalizedSiteUrl() !== '';
    }

    public function verify(?string $jws, ?int $now = null): bool
    {
        if (! $this->isConfigured() || $jws === null || substr_count($jws, '.') !== 2) {
            return false;
        }

        [$encodedHeader, $encodedClaims, $signature] = explode('.', $jws);

        $header = self::decode($encodedHeader);
        if ($header === null || ($header['alg'] ?? null) !== 'HS256' || isset($header['crit'])
            || (isset($header['typ']) && strtoupper((string) $header['typ']) !== 'JWT')) {
            return false;
        }

        $expected = self::base64UrlEncode(hash_hmac('sha256', $encodedHeader.'.'.$encodedClaims, $this->secret, true));
        if (! hash_equals($expected, $signature)) {
            return false;
        }

        $claims = self::decode($encodedClaims);

        return $claims !== null
            && ($claims['iss'] ?? null) === 'netlify'
            && is_int($claims['exp'] ?? null)
            && $claims['exp'] + self::LEEWAY_SECONDS >= ($now ?? time())
            && ($claims['deploy_context'] ?? null) === 'production'
            && is_string($claims['site_url'] ?? null)
            && rtrim($claims['site_url'], '/') === $this->normalizedSiteUrl();
    }

    public static function base64UrlEncode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function decode(string $segment): ?array
    {
        if ($segment === '' || preg_match('/[^A-Za-z0-9_-]/', $segment)) {
            return null;
        }

        $json = base64_decode(strtr($segment, '-_', '+/'), true);
        $data = $json === false ? null : json_decode($json, true);

        return is_array($data) ? $data : null;
    }

    private function normalizedSiteUrl(): string
    {
        return rtrim($this->siteUrl, '/');
    }
}
