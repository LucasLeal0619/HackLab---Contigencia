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
    public const PENDING = [
        // Triggers (18 ativos no PostgreSQL; account_access_grants_role_rules foi removido com a tabela na 000032).
        'audit_logs_no_update_delete',
        'users_sector_matches_role',
        'occurrence_interactions_append_only',
        'task_interactions_append_only',
        'occurrences_assignee_coherent',
        'occurrences_immutable_fields',
        'tasks_assignee_coherent',
        'tasks_immutable_fields',
        'users_open_assignments_guard',
        'evaluation_scores_guard',
        'evaluations_guard',
        'public_votes_guard',
        'voting_eligibilities_guard',
        'voting_session_teams_guard',
        'voting_sessions_guard',
        'result_publications_guard',
        'evaluations_results_lock',
        'juror_team_assignments_results_lock',
        'account_access_grants_role_rules',
    ];

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
