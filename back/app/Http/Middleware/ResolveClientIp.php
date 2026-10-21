<?php

namespace App\Http\Middleware;

use App\Support\Http\NetlifyProxySignature;
use App\Support\Http\ProxyConfiguration;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * IP real do cliente atrás de Netlify → Render (HACKLAB-IP-001), num ponto só: depois daqui o
 * $request->ip() (Auditoria, rate limits) devolve o IP resolvido.
 *
 * Só age quando a requisição chega pelo proxy local exato (TRUSTED_PROXIES, nunca "*"). Então descarta a
 * cadeia X-Forwarded-For recebida (o cliente pode escrever nela) e põe no lugar um único IP, de uma fonte
 * autenticada:
 *   1. x-nf-sign válido (veio pelo proxy do nosso site no Netlify) → x-nf-client-connection-ip;
 *   2. senão, o cabeçalho que a borda do host sobrescreve (Render: CF-Connecting-IP) → quem conectou no Render;
 *   3. senão, nenhum: o ip() fica sendo o proxy local. Nunca um IP de cabeçalho não autenticado.
 *
 * O Symfony calcula o ip() sob demanda (cadeia X-Forwarded-For + REMOTE_ADDR, retirando os proxies confiáveis),
 * então basta este middleware rodar antes de qualquer leitura do IP: ele é o primeiro do stack global.
 */
class ResolveClientIp
{
    public function handle(Request $request, Closure $next): Response
    {
        $proxies = ProxyConfiguration::exactProxies(config('app.trusted_proxies'));
        $remote = (string) $request->server->get('REMOTE_ADDR');

        if ($proxies === [] || ! IpUtils::checkIp($remote, $proxies)) {
            return $next($request);
        }

        $client = $this->resolve($request);

        $request->headers->remove('X-Forwarded-For');
        if ($client !== null) {
            $request->headers->set('X-Forwarded-For', $client);
        }
        // Já usados; não seguem adiante.
        $request->headers->remove('x-nf-sign');
        $request->headers->remove('x-nf-client-connection-ip');

        return $next($request);
    }

    private function resolve(Request $request): ?string
    {
        if (NetlifyProxySignature::fromConfig()->verify($request->headers->get('x-nf-sign'))) {
            $ip = self::validIp($request->headers->get('x-nf-client-connection-ip'));
            if ($ip !== null) {
                return $ip;
            }
        }

        $edgeHeader = trim((string) config('app.edge_client_ip_header'));

        return $edgeHeader === '' ? null : self::validIp($request->headers->get($edgeHeader));
    }

    /** Um único IPv4/IPv6 válido; listas, portas e lixo são recusados. */
    private static function validIp(?string $value): ?string
    {
        $value = trim((string) $value);

        return filter_var($value, FILTER_VALIDATE_IP) === false ? null : $value;
    }
}
