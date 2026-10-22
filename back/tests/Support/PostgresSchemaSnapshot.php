<?php

namespace Tests\Support;

use Illuminate\Database\Connection;

/**
 * Retrato do schema PostgreSQL do HackLab lido do catálogo (HACKLAB-DEV-001, etapa 3): tabelas, colunas, índices,
 * constraints (PK, FK, UNIQUE, CHECK), triggers, funções e sequences do schema `hacklab`.
 *
 * Serve de referência para provar que o caminho PostgreSQL das migrations não muda quando o caminho MySQL for
 * acrescentado. Só leitura de catálogo; a tabela `migrations` entra só pela estrutura.
 */
class PostgresSchemaSnapshot
{
    public const FIXTURE = __DIR__.'/../Fixtures/pgsql-schema.json';

    /**
     * @return array<string, mixed>
     */
    public static function capture(Connection $db, string $schema = 'hacklab'): array
    {
        $rows = fn (string $sql) => array_map(fn ($row) => (array) $row, $db->select($sql, [$schema]));

        return [
            'schema' => $schema,
            'tables' => array_column($rows(
                "select table_name from information_schema.tables where table_schema = ? and table_type = 'BASE TABLE' order by table_name"
            ), 'table_name'),
            'columns' => $rows(
                'select table_name, column_name, data_type, udt_name, is_nullable, column_default,
                        character_maximum_length, numeric_precision, numeric_scale, datetime_precision, is_generated, generation_expression
                   from information_schema.columns where table_schema = ? order by table_name, column_name'
            ),
            'indexes' => $rows(
                'select tablename as table_name, indexname as index_name, indexdef as definition
                   from pg_indexes where schemaname = ? order by tablename, indexname'
            ),
            'constraints' => $rows(
                "select rel.relname as table_name, con.conname as constraint_name, con.contype as type,
                        pg_get_constraintdef(con.oid, true) as definition
                   from pg_constraint con
                   join pg_class rel on rel.oid = con.conrelid
                   join pg_namespace nsp on nsp.oid = rel.relnamespace
                  where nsp.nspname = ? order by rel.relname, con.conname"
            ),
            'triggers' => $rows(
                'select rel.relname as table_name, tg.tgname as trigger_name, pg_get_triggerdef(tg.oid, true) as definition,
                        tg.tgenabled as enabled
                   from pg_trigger tg
                   join pg_class rel on rel.oid = tg.tgrelid
                   join pg_namespace nsp on nsp.oid = rel.relnamespace
                  where nsp.nspname = ? and not tg.tgisinternal order by rel.relname, tg.tgname'
            ),
            'functions' => $rows(
                'select p.proname as function_name, pg_get_function_identity_arguments(p.oid) as arguments,
                        pg_get_functiondef(p.oid) as definition
                   from pg_proc p join pg_namespace nsp on nsp.oid = p.pronamespace
                  where nsp.nspname = ? order by p.proname, 2'
            ),
            'sequences' => $rows(
                'select sequence_name, data_type, start_value, minimum_value, maximum_value, increment, cycle_option
                   from information_schema.sequences where sequence_schema = ? order by sequence_name'
            ),
        ];
    }

    public static function encode(array $snapshot): string
    {
        return json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";
    }
}
