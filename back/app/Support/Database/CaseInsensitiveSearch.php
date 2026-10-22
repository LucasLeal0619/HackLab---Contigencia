<?php

namespace App\Support\Database;

use Illuminate\Database\Query\Builder;

/**
 * Busca textual sem diferenciar maiúsculas e diferenciando acentos, igual nos dois bancos (HACKLAB-DEV-001):
 *
 * - PostgreSQL (produção, testes oficiais): `coluna::text ilike ?` — exatamente o SQL de antes;
 * - MySQL (desenvolvimento local): `coluna collate utf8mb4_0900_as_ci like ?` — a collation explícita vale qualquer
 *   que seja a da coluna (as = acento conta, ci = maiúscula não conta), então "maria" acha "MARIA" e não acha "Mária".
 *
 * O `whereLike()` do Laravel não serve: no MySQL ele vira `like` puro e passa a depender da collation da coluna.
 * Uso: ->whereILike('full_name', "%{$s}%") e ->orWhereILike(...), também dentro de whereHas.
 */
class CaseInsensitiveSearch
{
    public const MYSQL_COLLATION = 'utf8mb4_0900_as_ci';

    public static function register(): void
    {
        Builder::macro('whereILike', function (string $column, string $value, string $boolean = 'and') {
            /** @var Builder $this */
            $wrapped = $this->getGrammar()->wrap($column);
            $sql = $this->getConnection()->getDriverName() === 'mysql'
                ? "{$wrapped} collate ".CaseInsensitiveSearch::MYSQL_COLLATION.' like ?'
                : "{$wrapped}::text ilike ?"; // idêntico ao where($col, 'ilike', $v) do Laravel no PostgreSQL

            return $this->whereRaw($sql, [$value], $boolean);
        });

        Builder::macro('orWhereILike', function (string $column, string $value) {
            /** @var Builder $this */
            return $this->whereILike($column, $value, 'or');
        });
    }
}
