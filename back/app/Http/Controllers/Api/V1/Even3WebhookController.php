<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Integrations\Even3WebhookService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /api/v1/webhooks/even3 — público (sem Sanctum), protegido pelo token de segurança do webhook,
 * enviado no header configurado em EVEN3_WEBHOOK_HEADER (nome a confirmar no PoC real; sem palpite).
 *
 * Respostas curtas: nunca ecoa token, segredo ou payload.
 */
class Even3WebhookController extends Controller
{
    public function __invoke(Request $request, Even3WebhookService $webhooks): JsonResponse
    {
        $header = (string) config('services.even3.webhook_header');
        $secret = (string) config('services.even3.webhook_secret');
        $apiToken = (string) config('services.even3.token');

        if (! config('services.even3.enabled') || $header === '' || $secret === '') {
            return response()->json(['message' => 'Webhook Even3 não configurado.', 'errors' => (object) []], 503);
        }

        if ($apiToken !== '' && hash_equals($apiToken, $secret)) {
            // Segredo do webhook igual ao token da API = configuração insegura: recusa tudo.
            return response()->json(['message' => 'Webhook Even3 mal configurado.', 'errors' => (object) []], 503);
        }

        $provided = $request->header($header);

        if (! is_string($provided) || ! hash_equals($secret, $provided)) {
            return response()->json(['message' => 'Não autenticado.', 'errors' => (object) []], 401);
        }

        $raw = $request->getContent();
        $payload = json_decode($raw, true);

        if (! is_array($payload) || ! is_scalar($payload['id'] ?? null) || (string) $payload['id'] === '') {
            return response()->json(['message' => 'Payload inválido: identificador do disparo ausente.', 'errors' => (object) []], 400);
        }

        [$status, $receipt] = $webhooks->handle($payload, $raw);

        return response()->json(['data' => [
            'receipt_id' => $receipt->id,
            'status' => $receipt->status->value,
            'error_code' => $receipt->error_code,
        ]], $status);
    }
}
