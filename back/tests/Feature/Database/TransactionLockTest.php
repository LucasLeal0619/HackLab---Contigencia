<?php

namespace Tests\Feature\Database;

use App\Support\Database\Dialect;
use App\Support\Database\TransactionLock;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\DatabaseTestCase;

/**
 * Trava de transação portável (HACKLAB-DEV-001, etapa 6). A exclusão mútua entre processos é provada pelos testes
 * de concorrência real (Even3RealConcurrencyTest); aqui, o contrato: só dentro de transação, o SQL do PostgreSQL é
 * o de sempre e, no MySQL, a chave vira uma linha travada que some junto com a transação desfeita.
 */
class TransactionLockTest extends DatabaseTestCase
{
    public function test_refuses_to_run_outside_a_transaction(): void
    {
        // O teste em si roda dentro de uma transação; uma conexão nova ao mesmo banco está fora dela.
        config(['database.connections.lock_probe' => config('database.connections.'.DB::getDefaultConnection())]);
        $outside = DB::connection('lock_probe');

        try {
            $this->expectException(LogicException::class);
            TransactionLock::acquire(1, 1, $outside);
        } finally {
            DB::purge('lock_probe');
        }
    }

    public function test_postgresql_keeps_the_advisory_lock_sql(): void
    {
        if (Dialect::isMysql()) {
            $this->markTestSkipped('SQL do PostgreSQL; no MySQL vale o teste abaixo.');
        }

        DB::enableQueryLog();
        DB::transaction(function () {
            TransactionLock::acquire(91003, 7);
            TransactionLock::acquire(91005, 'entrega-123');
        });

        $sql = array_column(DB::getQueryLog(), 'query');
        $this->assertContains('select pg_advisory_xact_lock(?, ?)', $sql);
        $this->assertContains('select pg_advisory_xact_lock(?, hashtext(?))', $sql);
    }

    public function test_mysql_lock_row_is_created_inside_the_transaction_and_reused(): void
    {
        if (! Dialect::isMysql()) {
            $this->markTestSkipped('Linha de trava só existe no MySQL.');
        }

        DB::transaction(function () {
            TransactionLock::acquire(91003, 7);
            TransactionLock::acquire(91003, 7); // reentrante na mesma transação
            TransactionLock::acquire(91005, 'entrega-123');
        });

        $this->assertSame(1, DB::table('transaction_locks')->where('lock_key', '91003:7')->count());
        $this->assertSame(1, DB::table('transaction_locks')->where('lock_key', '91005:'.sha1('entrega-123'))->count());
    }
}
