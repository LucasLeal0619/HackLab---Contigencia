<?php

namespace App\Domain\Results;

/**
 * Ranking por competição: posição = 1 + quantidade de itens com valor estritamente maior.
 * Empate de primeiro lugar → 1, 1, 3. Sem desempate artificial (ID, cadastro, hora, nome, outro domínio).
 *
 * A ordem alfabética do rótulo é só apresentação dentro da mesma posição. Itens sem valor (null)
 * ficam sem posição, no fim.
 */
final class Ranking
{
    /**
     * @template T of array<string, mixed>
     *
     * @param  list<T>  $rows
     * @param  callable(T): (int|string|null)  $value
     * @param  callable(int|string, int|string): int  $compare  <0, 0, >0 como <=>
     * @param  callable(T): string  $label
     * @return list<T&array{rank: ?int, tied: bool}>
     */
    public static function competition(array $rows, callable $value, callable $compare, callable $label): array
    {
        $values = array_map($value, $rows);

        $ranked = [];

        foreach ($rows as $i => $row) {
            $mine = $values[$i];

            if ($mine === null) {
                $ranked[] = $row + ['rank' => null, 'tied' => false];

                continue;
            }

            $greater = 0;
            $equal = 0;

            foreach ($values as $other) {
                if ($other === null) {
                    continue;
                }

                $cmp = $compare($other, $mine);
                $greater += $cmp > 0 ? 1 : 0;
                $equal += $cmp === 0 ? 1 : 0;
            }

            $ranked[] = $row + ['rank' => $greater + 1, 'tied' => $equal > 1];
        }

        usort($ranked, function (array $a, array $b) use ($label) {
            if ($a['rank'] !== $b['rank']) {
                return match (true) {
                    $a['rank'] === null => 1,
                    $b['rank'] === null => -1,
                    default => $a['rank'] <=> $b['rank'],
                };
            }

            return strcasecmp($label($a), $label($b));
        });

        return $ranked;
    }

    /**
     * @param  list<array{tied: bool}>  $rows
     */
    public static function hasTies(array $rows): bool
    {
        foreach ($rows as $row) {
            if ($row['tied']) {
                return true;
            }
        }

        return false;
    }
}
