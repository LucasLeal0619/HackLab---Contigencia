<?php

namespace App\Support\Http;

use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\URL;

/**
 * Proxies confiáveis e origem das URLs (HACKLAB-IP-001).
 *
 * - só X-Forwarded-For e X-Forwarded-Proto valem; X-Forwarded-Host/-Port/-Prefix nunca (host header injection);
 * - em production, URLs absolutas sempre a partir do APP_URL, nunca do Host recebido.
 */
class ProxyConfiguration
{
    public const TRUSTED_HEADERS = Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_PROTO;

    public static function apply(?string $trustedProxies, string $environment, ?string $appUrl): void
    {
        TrustProxies::withHeaders(self::TRUSTED_HEADERS);

        $proxies = trim((string) $trustedProxies);
        if ($proxies !== '') {
            TrustProxies::at($proxies === '*' ? '*' : self::exactProxies($proxies));
        }

        if ($environment === 'production' && filled($appUrl)) {
            URL::forceRootUrl(rtrim($appUrl, '/'));
            if (str_starts_with($appUrl, 'https://')) {
                URL::forceScheme('https');
            }
            // Links de paginação: pelo gerador de URLs (APP_URL), não pelo Host da requisição.
            Paginator::currentPathResolver(fn () => URL::current());
        }
    }

    /**
     * Lista explícita de proxies (IPs/CIDR). "*" não é uma lista: devolve vazio.
     *
     * @return list<string>
     */
    public static function exactProxies(?string $trustedProxies): array
    {
        $proxies = trim((string) $trustedProxies);
        if ($proxies === '' || $proxies === '*') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $proxies)), fn (string $p) => $p !== '' && $p !== '*'));
    }
}
