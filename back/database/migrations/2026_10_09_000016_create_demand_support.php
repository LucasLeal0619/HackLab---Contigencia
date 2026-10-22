<?php

use App\Support\Database\Dialect;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Apoio a pendências e ocorrências:
 * - next_reference(): referência legível (PEN-0001) a partir de uma sequence, segura sob concorrência;
 * - forbid_update_delete(): trigger genérico para tabelas somente inserção (históricos);
 * - unique(id, event_id) em event_days, alvo da FK composta de occurrences.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Dialect::isMysql()) {
            // MySQL não tem sequence: um contador por prefixo, incrementado com trava de linha pelo trigger que
            // preenche a referência (000017/000018). forbid_update_delete vira triggers *_append_only_upd/_del.
            DB::statement('create table reference_counters (prefix varchar(8) not null primary key, counter_value bigint unsigned not null default 0) engine=InnoDB');
            DB::statement("insert into reference_counters (prefix, counter_value) values ('OCO', 0), ('PEN', 0)");
        } else {
            $this->createPostgresFunctions();
        }

        Schema::table('event_days', function (Blueprint $table) {
            $table->unique(['id', 'event_id']);
        });
    }

    private function createPostgresFunctions(): void
    {
        DB::unprepared(<<<'SQL'
            create or replace function next_reference(prefix text, seq text) returns text language plpgsql as $$
            declare
                n bigint;
            begin
                n := nextval(seq::regclass);
                -- Pelo menos 4 dígitos, sem truncar acima de 9999 (PEN-10000).
                return prefix || '-' || lpad(n::text, greatest(4, length(n::text)), '0');
            end;
            $$;

            create or replace function forbid_update_delete() returns trigger language plpgsql as $$
            begin
                raise exception '% é somente inserção (% recusado)', tg_table_name, tg_op;
            end;
            $$;
            SQL);
    }

    public function down(): void
    {
        Schema::table('event_days', function (Blueprint $table) {
            $table->dropUnique(['id', 'event_id']);
        });

        if (Dialect::isMysql()) {
            DB::statement('drop table if exists reference_counters');
        } else {
            DB::unprepared('drop function if exists forbid_update_delete()');
            DB::unprepared('drop function if exists next_reference(text, text)');
        }
    }
};
