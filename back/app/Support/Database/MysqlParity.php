<?php

namespace App\Support\Database;

use LogicException;

/**
 * Proteções do PostgreSQL ainda sem equivalente no MySQL (HACKLAB-DEV-001). Nada é omitido em silêncio:
 *
 * - a migration, no caminho MySQL, chama pending('<nome>') no lugar do SQL do PostgreSQL;
 * - só nomes desta lista fechada são aceitos — qualquer outro derruba a migration;
 * - o teste MySQL de paridade falha enquanto a lista não estiver vazia, e o hacklab:db-check mostra o que falta.
 *
 * Etapa 5 implementa cada item (trigger MySQL equivalente) e o remove daqui. Já implementado: referências
 * OCO-/PEN- (triggers occurrences_reference_default e tasks_reference_default + tabela reference_counters).
 */
final class MysqlParity
{
    /**
     * Vazia desde a etapa 5: os 19 triggers do PostgreSQL têm equivalente MySQL (App\Support\Database\MysqlTrigger),
     * provado pelo teste de paridade a partir da referência do schema. Proteção nova no PostgreSQL entra aqui só
     * de passagem, até ganhar o equivalente.
     *
     * @var list<string>
     */
    public const PENDING = [];

    /** @var array<string, true> */
    private static array $skipped = [];

    public static function pending(string $mechanism): void
    {
        if (! in_array($mechanism, self::PENDING, true)) {
            throw new LogicException("Proteção \"{$mechanism}\" do PostgreSQL sem equivalente MySQL e fora de MysqlParity::PENDING: implemente o equivalente em vez de omitir.");
        }

        self::$skipped[$mechanism] = true;
    }

    /**
     * @return list<string>
     */
    public static function skipped(): array
    {
        return array_keys(self::$skipped);
    }
}
