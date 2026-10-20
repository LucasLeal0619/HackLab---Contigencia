<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * TEMPORÁRIO (SPEC HACKLAB-IP-001): mostra como a requisição chega ao Laravel atrás de Netlify → Render,
 * para decidir a fronteira de confiança do IP do cliente. Remover depois da correção.
 *
 * - desligado (404) sem HACKLAB_REQUEST_DIAGNOSTICS_TOKEN; com ele, só responde a quem envia o mesmo valor
 *   em X-Diagnostics-Token (comparação em tempo constante); token errado também é 404;
 * - devolve só cabeçalhos de roteamento/IP numa lista fechada; dos demais, só os nomes. Nunca cookies,
 *   Authorization, CSRF, o token de diagnóstico nem o valor do x-nf-sign.
 */
class RequestDiagnosticsController extends Controller
{
    private const VALUE_HEADERS = [
        'x-forwarded-for', 'x-forwarded-proto', 'x-forwarded-host', 'x-forwarded-port', 'x-forwarded-prefix',
        'forwarded', 'x-real-ip', 'true-client-ip', 'cf-connecting-ip', 'x-nf-client-connection-ip', 'via',
    ];

    private const HIDDEN_HEADERS = ['cookie', 'authorization', 'x-xsrf-token', 'x-csrf-token', 'x-diagnostics-token'];

    public function __invoke(Request $request): JsonResponse
    {
        $token = (string) config('app.request_diagnostics_token');
        if ($token === '' || ! hash_equals($token, (string) $request->header('X-Diagnostics-Token'))) {
            throw new NotFoundHttpException;
        }

        $headers = [];
        foreach (self::VALUE_HEADERS as $name) {
            $headers[$name] = $request->headers->all($name) ?: null;
        }

        return response()->json(['data' => [
            'remote_addr' => $request->server('REMOTE_ADDR'),
            'laravel_ip' => $request->ip(),
            'laravel_client_ips' => $request->getClientIps(),
            'trusted_proxies_config' => config('app.trusted_proxies'),
            'is_secure' => $request->isSecure(),
            'scheme_and_host' => $request->getSchemeAndHttpHost(),
            'headers' => $headers,
            'x_nf_sign_present' => $request->headers->has('x-nf-sign'),
            'header_names' => array_values(array_diff(array_keys($request->headers->all()), self::HIDDEN_HEADERS)),
        ]])->header('Cache-Control', 'no-store');
    }
}
