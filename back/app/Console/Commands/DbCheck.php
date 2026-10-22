<?php

namespace App\Console\Commands;

use App\Support\Database\Dialect;
use App\Support\Database\MysqlParity;
use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class DbCheck extends Command
{
    protected $signature = 'hacklab:db-check {--connection= : Conexão a verificar (padrão: DB_CONNECTION)}';

    protected $description = 'Valida a conexão do HackLab: PostgreSQL (servidor, banco, schema, SSL, migrations) ou MySQL local (versão, collation, isolamento, migrations)';

    public function handle(): int
    {
        $name = $this->option('connection') ?: config('database.default');

        try {
            $connection = DB::connection($name);

            if ($connection->getDriverName() === 'mysql') {
                return $this->checkMysql($name, $connection);
            }

            if ($connection->getDriverName() !== 'pgsql') {
                $this->error("A conexão \"{$name}\" usa o driver \"{$connection->getDriverName()}\". O HackLab usa pgsql (produção) ou mysql (desenvolvimento local).");

                return self::FAILURE;
            }

            $expectedSchema = $connection->getConfig('search_path');

            $info = $connection->selectOne(<<<'SQL'
                select
                    current_setting('server_version') as version,
                    current_database() as database,
                    current_user as "user",
                    current_schema() as schema,
                    current_setting('search_path') as search_path,
                    current_setting('server_encoding') as encoding,
                    coalesce((select ssl from pg_stat_ssl where pid = pg_backend_pid()), false) as ssl
                SQL);

            $hasMigrationsTable = $info->schema !== null
                && $connection->getSchemaBuilder()->hasTable('migrations');

            $migrations = $hasMigrationsTable ? $connection->table('migrations')->count() : null;
        } catch (Throwable $e) {
            $this->error('Falha ao conectar: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->table(['Item', 'Valor'], [
            ['Ambiente (APP_ENV)', app()->environment()],
            ['Conexão', $name],
            ['Host', $connection->getConfig('host').':'.$connection->getConfig('port')],
            ['Servidor', 'PostgreSQL '.$info->version],
            ['Banco', $info->database],
            ['Usuário', $info->user],
            ['Encoding', $info->encoding],
            ['search_path', $info->search_path],
            ['Schema ativo', $info->schema ?? '(nenhum: o schema não existe)'],
            ['SSL', ($info->ssl ? 'sim' : 'não').' (sslmode '.$connection->getConfig('sslmode').')'],
            ['Migrations aplicadas', $migrations === null ? 'nenhuma (rode php artisan migrate)' : (string) $migrations],
        ]);

        if ($info->schema !== $expectedSchema) {
            $this->error("O schema \"{$expectedSchema}\" não existe neste banco. Crie-o antes do migrate (veja hacklab_docs/docs/banco-de-dados.md).");

            return self::FAILURE;
        }

        $this->info('Conexão OK.');

        return self::SUCCESS;
    }

    /**
     * MySQL local da equipe (HACKLAB-DEV-001): MySQL Server 8.0.19+ (nunca MariaDB), utf8mb4_0900_as_cs e READ COMMITTED.
     */
    private function checkMysql(string $name, Connection $connection): int
    {
        try {
            $version = Dialect::ensureSupportedMysql($connection);
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $info = $connection->selectOne('select database() as db, current_user() as usr, @@collation_connection as coll, @@transaction_isolation as iso');
        $migrations = $connection->getSchemaBuilder()->hasTable('migrations') ? $connection->table('migrations')->count() : null;

        $this->table(['Item', 'Valor'], [
            ['Ambiente (APP_ENV)', app()->environment()],
            ['Conexão', $name],
            ['Host', $connection->getConfig('host').':'.$connection->getConfig('port')],
            ['Servidor', 'MySQL '.$version.' (mínimo '.Dialect::MYSQL_MINIMUM.')'],
            ['Banco', $info->db],
            ['Usuário', $info->usr],
            ['Collation', $info->coll],
            ['Isolamento', $info->iso],
            ['Migrations aplicadas', $migrations === null ? 'nenhuma (rode php artisan migrate)' : (string) $migrations],
            ['Proteções do PostgreSQL ainda sem equivalente', (string) count(MysqlParity::PENDING).' (etapa 5)'],
        ]);

        $this->info('Conexão OK.');

        return self::SUCCESS;
    }
}
