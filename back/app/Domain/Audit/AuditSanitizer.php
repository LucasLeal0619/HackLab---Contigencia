<?php

namespace App\Domain\Audit;

/**
 * Remove segredos de payloads de auditoria, recursivamente, em qualquer nível.
 *
 * Única implementação: usada pelo AuditLogger ao gravar e, por defesa em profundidade, pelo detalhe da API.
 * A comparação ignora caixa, "_" e "-" (api_key = apiKey = api-key). "session" sozinho não é sensível:
 * voting_session_id é campo legítimo do domínio.
 */
final class AuditSanitizer
{
    private const SENSITIVE_FRAGMENTS = [
        'password', 'token', 'secret', 'cookie', 'authorization', 'apikey', 'remember',
        'csrf', 'privatekey', 'accesskey',
    ];

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    public static function sanitize(array $data): array
    {
        $clean = [];

        foreach ($data as $key => $value) {
            if (is_string($key) && self::isSensitive($key)) {
                continue;
            }

            $clean[$key] = is_array($value) ? self::sanitize($value) : $value;
        }

        return $clean;
    }

    /**
     * @param  array<array-key, mixed>|null  $data
     * @return array<array-key, mixed>|null
     */
    public static function sanitizeNullable(?array $data): ?array
    {
        return $data === null ? null : self::sanitize($data);
    }

    public static function isSensitive(string $key): bool
    {
        $normalized = str_replace(['_', '-', ' '], '', mb_strtolower($key));

        foreach (self::SENSITIVE_FRAGMENTS as $fragment) {
            if (str_contains($normalized, $fragment)) {
                return true;
            }
        }

        return false;
    }
}
