<?php

namespace Tests;

use App\Domain\Users\Enums\RoleCode;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

/**
 * Base dos testes de concorrência REAL: processos PHP separados (tests/Concurrency/worker.php), cada um com a
 * própria conexão, disparando juntos contra o PostgreSQL (barreira por advisory lock).
 *
 * Não usa RefreshDatabase (a transação do teste esconderia os dados dos workers): os dados são gravados de
 * verdade no hacklab_test e o banco é truncado antes e depois de cada teste.
 */
abstract class RealConcurrencyTestCase extends TestCase
{
    protected const BARRIER = 77_000_001;

    protected function setUp(): void
    {
        parent::setUp();

        if (! RefreshDatabaseState::$migrated) {
            $this->artisan('migrate:fresh');
            RefreshDatabaseState::$migrated = true;
        }

        $this->truncateAll();
        $this->seed(RolePermissionSeeder::class);
    }

    protected function tearDown(): void
    {
        // Devolve o hacklab_test ao estado-base (só perfis/permissões) para as demais classes.
        $this->truncateAll();
        $this->seed(RolePermissionSeeder::class);

        parent::tearDown();
    }

    protected function truncateAll(): void
    {
        $tables = collect(DB::select("select tablename from pg_tables where schemaname = current_schema() and tablename <> 'migrations'"))
            ->map(fn ($row) => '"'.$row->tablename.'"')->implode(', ');

        DB::statement("truncate table {$tables} restart identity cascade");
    }

    protected function admin(array $attributes = []): User
    {
        return User::factory()->role(RoleCode::Administrator)->create($attributes);
    }

    protected function userWithRole(RoleCode $role, array $attributes = []): User
    {
        return User::factory()->role($role)->create($attributes);
    }

    /**
     * Dispara os jobs ao mesmo tempo e devolve a saída de cada worker, na ordem dos jobs.
     *
     * @param  list<array{0: string, 1: array<string, mixed>}>  $jobs
     * @return list<array<string, mixed>>
     */
    protected function runConcurrently(array $jobs): array
    {
        DB::select('select pg_advisory_lock(?)', [self::BARRIER]);

        $processes = [];

        try {
            foreach ($jobs as [$operation, $args]) {
                $process = new Process(
                    ['php', base_path('tests/Concurrency/worker.php'), $operation, json_encode($args + ['barrier' => self::BARRIER])],
                    base_path(),
                    ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'pgsql', 'DB_DATABASE' => 'hacklab_test', 'DB_URL' => ''],
                    timeout: 180,
                );
                $process->start();
                $processes[] = $process;
            }

            // Espera todos chegarem à barreira (bloqueados no advisory lock) antes de liberar.
            // Prazo folgado: o bootstrap do PHP no bind-mount do Docker/Windows é lento.
            $deadline = microtime(true) + 120;
            while ((int) DB::scalar("select count(*) from pg_locks where locktype = 'advisory' and objid = ? and not granted", [self::BARRIER]) < count($jobs)) {
                foreach ($processes as $process) {
                    if (! $process->isRunning()) {
                        $this->fail('Worker terminou antes da barreira: '.$process->getOutput().$process->getErrorOutput());
                    }
                }
                if (microtime(true) > $deadline) {
                    $this->fail('Workers não chegaram à barreira a tempo.');
                }
                usleep(20_000);
            }
        } finally {
            DB::select('select pg_advisory_unlock(?)', [self::BARRIER]);
        }

        return array_map(function (Process $process) {
            $process->wait();
            $line = trim($process->getOutput());
            $decoded = json_decode($line, true);
            $this->assertIsArray($decoded, 'Saída inválida do worker: '.$line.$process->getErrorOutput());
            $this->assertNotSame(500, $decoded['status'], 'Worker com erro inesperado: '.($decoded['error'] ?? $line));

            return $decoded;
        }, $processes);
    }

    /**
     * @param  list<array<string, mixed>>  $results
     */
    protected function countWhere(array $results, callable $filter): int
    {
        return count(array_filter($results, $filter));
    }
}
