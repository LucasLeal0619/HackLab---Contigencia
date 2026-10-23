<?php

namespace App\Support\Database;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Trava exclusiva que vale até o fim da transação e é solta no commit ou no rollback (HACKLAB-DEV-001, etapa 6).
 *
 * - PostgreSQL: pg_advisory_xact_lock(classe, chave) — exatamente o SQL de antes (chave texto: hashtext(...));
 * - MySQL: não há advisory lock de transação (GET_LOCK vale a sessão inteira). Equivalente fiel: uma linha por
 *   chave em transaction_locks, com trava exclusiva de linha (insert ... on duplicate key update) — o InnoDB solta
 *   no commit/rollback.
 *
 * Mesma garantia nos dois: duas transações com a mesma (classe, chave) nunca rodam o trecho protegido ao mesmo
 * tempo; a segunda espera a primeira terminar.
 */
final class TransactionLock
{
    public static function acquire(int $class, int|string $key, ?Connection $connection = null): void
    {
        $connection ??= DB::connection();

        if ($connection->transactionLevel() < 1) {
            throw new LogicException('TransactionLock só vale dentro de uma transação (DB::transaction).');
        }

        if (Dialect::isMysql($connection)) {
            $name = $class.':'.(is_int($key) ? $key : sha1($key));
            // Uma instrução só: insere a chave ou, se já existe, pega a trava EXCLUSIVA da linha (o InnoDB não passa
            // pela trava compartilhada no "on duplicate key update"). INSERT IGNORE + SELECT ... FOR UPDATE dava
            // deadlock com 3+ transações disputando uma chave nova (S -> X), visto no teste de concorrência real.
            $connection->insert('insert into transaction_locks (lock_key) values (?) on duplicate key update lock_key = lock_key', [$name]);

            return;
        }

        is_int($key)
            ? $connection->select('select pg_advisory_xact_lock(?, ?)', [$class, $key])
            : $connection->select('select pg_advisory_xact_lock(?, hashtext(?))', [$class, $key]);
    }
}
