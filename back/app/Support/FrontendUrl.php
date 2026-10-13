<?php

namespace App\Support;

/**
 * Links do frontend (SPA com rotas em hash) a partir de FRONTEND_URL. A base vem só da configuração:
 * nunca de dados do pedido (sem open redirect).
 */
final class FrontendUrl
{
    /**
     * @param  array<string, string>  $query
     */
    public static function to(string $route, array $query = []): string
    {
        $base = rtrim((string) config('app.frontend_url'), '/');
        $qs = $query === [] ? '' : '?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);

        return "{$base}/#/{$route}{$qs}";
    }
}
