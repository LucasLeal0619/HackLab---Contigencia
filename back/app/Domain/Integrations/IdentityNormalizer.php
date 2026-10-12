<?php

namespace App\Domain\Integrations;

/**
 * Normalização usada no matching de pessoas vindas da plataforma externa.
 */
final class IdentityNormalizer
{
    /** Documento: maiúsculas, só letras e dígitos (CPF com ou sem pontuação, passaporte). */
    public static function document(?string $value): ?string
    {
        $normalized = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', (string) $value));

        return $normalized === '' ? null : $normalized;
    }

    /** E-mail: trim + minúsculas. */
    public static function email(?string $value): ?string
    {
        $normalized = mb_strtolower(trim((string) $value));

        return $normalized === '' ? null : $normalized;
    }

    /** Telefone: conservador — só dígitos, mantendo "+" inicial se houver. Não inventa DDI. */
    public static function phone(?string $value): ?string
    {
        $value = trim((string) $value);
        $digits = (string) preg_replace('/\D/', '', $value);

        if ($digits === '') {
            return null;
        }

        return mb_substr((str_starts_with($value, '+') ? '+' : '').$digits, 0, 32);
    }

    /** Rótulo de entrada para comparação: sem diferença de caixa e espaços repetidos. */
    public static function label(?string $value): ?string
    {
        $normalized = mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', (string) $value)));

        return $normalized === '' ? null : $normalized;
    }
}
