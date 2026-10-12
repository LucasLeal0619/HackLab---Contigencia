<?php

namespace App\Domain\Audit;

/**
 * Diferença before/after para visualização administrativa (derivada na consulta, nunca persistida).
 *
 * - objetos associativos aninhados viram caminhos com ponto (profile.role);
 * - listas são tratadas como um valor só (sem diff por índice);
 * - ausente ≠ null: exists_before/exists_after dizem se a chave existia de cada lado.
 *
 * Não é motor de versionamento: só compara dois instantâneos.
 */
final class AuditDiff
{
    /**
     * @param  array<array-key, mixed>|null  $before
     * @param  array<array-key, mixed>|null  $after
     * @return list<array{path: string, before: mixed, after: mixed, exists_before: bool, exists_after: bool}>
     */
    public static function changes(?array $before, ?array $after): array
    {
        $changes = [];
        self::compare($before ?? [], $after ?? [], '', $changes);

        return $changes;
    }

    /**
     * @param  array<array-key, mixed>  $before
     * @param  array<array-key, mixed>  $after
     * @param  list<array<string, mixed>>  $changes
     */
    private static function compare(array $before, array $after, string $prefix, array &$changes): void
    {
        $keys = array_values(array_unique([...array_keys($before), ...array_keys($after)]));

        foreach ($keys as $key) {
            $path = $prefix === '' ? (string) $key : "{$prefix}.{$key}";
            $existsBefore = array_key_exists($key, $before);
            $existsAfter = array_key_exists($key, $after);
            $old = $before[$key] ?? null;
            $new = $after[$key] ?? null;

            if ($existsBefore && $existsAfter && self::isObject($old) && self::isObject($new)) {
                self::compare($old, $new, $path, $changes);

                continue;
            }

            if ($existsBefore === $existsAfter && $old === $new) {
                continue;
            }

            $changes[] = ['path' => $path, 'before' => $old, 'after' => $new, 'exists_before' => $existsBefore, 'exists_after' => $existsAfter];
        }
    }

    /**
     * Objeto associativo não vazio (lista e [] são valores).
     */
    private static function isObject(mixed $value): bool
    {
        return is_array($value) && $value !== [] && ! array_is_list($value);
    }
}
