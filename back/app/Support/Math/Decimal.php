<?php

namespace App\Support\Math;

/**
 * Aritmética decimal (bcmath) para regras de resultado: comparar e ranquear sem erro de float.
 * Os valores trafegam como string numérica; a apresentação arredonda só no fim.
 */
final class Decimal
{
    /** Casas usadas nos cálculos intermediários (divisões truncam aqui, de forma determinística). */
    public const SCALE = 30;

    /**
     * 100 * part / whole, ou "0" quando whole = 0.
     */
    public static function percent(int|string $part, int|string $whole): string
    {
        if (bccomp((string) $whole, '0', self::SCALE) === 0) {
            return '0';
        }

        return bcdiv(bcmul((string) $part, '100', self::SCALE), (string) $whole, self::SCALE);
    }

    /**
     * Arredonda (meio para cima, valores >= 0) para apresentação.
     */
    public static function round(string $value, int $places = 2): float
    {
        $half = '0.'.str_repeat('0', $places).'5';

        return (float) bcadd($value, $half, $places);
    }

    public static function compare(string $a, string $b): int
    {
        return bccomp($a, $b, self::SCALE);
    }
}
