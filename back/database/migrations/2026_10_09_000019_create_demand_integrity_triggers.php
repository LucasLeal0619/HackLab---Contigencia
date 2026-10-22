<?php

use App\Support\Database\Dialect;
use App\Support\Database\MysqlParity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Regras de pendências/ocorrências reforçadas no PostgreSQL:
 * - referência, origem, evento (e source_occurrence_id da pendência) não mudam depois da criação;
 * - demanda aberta só pode ter como responsável individual um usuário ATIVO do setor responsável;
 * - usuário com demanda aberta atribuída não muda de setor nem é inativado.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Dialect::isMysql()) {
            MysqlParity::pending('tasks_immutable_fields');
            MysqlParity::pending('occurrences_immutable_fields');
            MysqlParity::pending('tasks_assignee_coherent');
            MysqlParity::pending('occurrences_assignee_coherent');
            MysqlParity::pending('users_open_assignments_guard');

            return;
        }

        DB::unprepared(<<<'SQL'
            create or replace function tasks_immutable_fields() returns trigger language plpgsql as $$
            begin
                if new.reference <> old.reference
                    or new.event_id <> old.event_id
                    or new.origin_sector_id <> old.origin_sector_id
                    or new.source_occurrence_id is distinct from old.source_occurrence_id then
                    raise exception 'tasks: referência, evento, origem e ocorrência de origem não mudam'
                        using errcode = 'check_violation';
                end if;
                return new;
            end;
            $$;

            create or replace function occurrences_immutable_fields() returns trigger language plpgsql as $$
            begin
                if new.reference <> old.reference
                    or new.event_id <> old.event_id
                    or new.origin_sector_id <> old.origin_sector_id then
                    raise exception 'occurrences: referência, evento e origem não mudam'
                        using errcode = 'check_violation';
                end if;
                return new;
            end;
            $$;

            create or replace function demand_assignee_coherent() returns trigger language plpgsql as $$
            begin
                if new.assigned_user_id is null or new.status in ('COMPLETED', 'RESOLVED') then
                    return new;
                end if;

                if not exists (
                    select 1 from users u
                    where u.id = new.assigned_user_id
                      and u.status = 'ACTIVE'
                      and u.sector_id = new.responsible_sector_id
                ) then
                    raise exception '%: responsável individual precisa ser usuário ativo do setor responsável', tg_table_name
                        using errcode = 'check_violation';
                end if;

                return new;
            end;
            $$;

            create or replace function users_open_assignments_guard() returns trigger language plpgsql as $$
            begin
                if new.sector_id is not distinct from old.sector_id and new.status = old.status then
                    return new;
                end if;

                if exists (
                    select 1 from tasks t
                    where t.assigned_user_id = new.id and t.status <> 'COMPLETED'
                      and (new.status <> 'ACTIVE' or t.responsible_sector_id is distinct from new.sector_id)
                ) or exists (
                    select 1 from occurrences o
                    where o.assigned_user_id = new.id and o.status <> 'RESOLVED'
                      and (new.status <> 'ACTIVE' or o.responsible_sector_id is distinct from new.sector_id)
                ) then
                    raise exception 'users: usuário tem pendência/ocorrência aberta atribuída; reatribua antes de mudar setor ou inativar'
                        using errcode = 'check_violation';
                end if;

                return new;
            end;
            $$;

            create trigger tasks_immutable_fields before update on tasks
                for each row execute function tasks_immutable_fields();
            create trigger occurrences_immutable_fields before update on occurrences
                for each row execute function occurrences_immutable_fields();

            create trigger tasks_assignee_coherent before insert or update of assigned_user_id, responsible_sector_id, status on tasks
                for each row execute function demand_assignee_coherent();
            create trigger occurrences_assignee_coherent before insert or update of assigned_user_id, responsible_sector_id, status on occurrences
                for each row execute function demand_assignee_coherent();

            create trigger users_open_assignments_guard before update of sector_id, status on users
                for each row execute function users_open_assignments_guard();
            SQL);
    }

    public function down(): void
    {
        if (Dialect::isMysql()) {
            return;
        }

        DB::unprepared(<<<'SQL'
            drop trigger if exists users_open_assignments_guard on users;
            drop trigger if exists occurrences_assignee_coherent on occurrences;
            drop trigger if exists tasks_assignee_coherent on tasks;
            drop trigger if exists occurrences_immutable_fields on occurrences;
            drop trigger if exists tasks_immutable_fields on tasks;
            drop function if exists users_open_assignments_guard();
            drop function if exists demand_assignee_coherent();
            drop function if exists occurrences_immutable_fields();
            drop function if exists tasks_immutable_fields();
            SQL);
    }
};
