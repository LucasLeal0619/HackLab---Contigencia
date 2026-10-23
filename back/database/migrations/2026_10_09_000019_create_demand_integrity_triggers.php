<?php

use App\Support\Database\Dialect;
use App\Support\Database\MysqlTrigger;
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
            $this->createMysqlTriggers();

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

    /**
     * Equivalentes MySQL (HACKLAB-DEV-001). No "before update", a ordem é a do PostgreSQL (alfabética):
     * *_assignee_coherent antes de *_immutable_fields; users_open_assignments_guard antes de users_sector_matches_role.
     */
    private function createMysqlTriggers(): void
    {
        // demand_assignee_coherent: responsável individual precisa ser usuário ativo do setor responsável.
        foreach (['tasks', 'occurrences'] as $table) {
            $rule = <<<SQL
                if new.assigned_user_id is not null and new.status not in ('COMPLETED', 'RESOLVED') then
                    if not exists (
                        select 1 from users u
                        where u.id = new.assigned_user_id and u.status = 'ACTIVE' and u.sector_id = new.responsible_sector_id
                    ) then
                        signal sqlstate '45000' set message_text = '{$table}: responsável individual precisa ser usuário ativo do setor responsável';
                    end if;
                end if;
                SQL;

            MysqlTrigger::create("{$table}_assignee_coherent_ins", 'insert', $table, $rule);
            // "update of assigned_user_id, responsible_sector_id, status": no MySQL, quando um deles muda.
            MysqlTrigger::create("{$table}_assignee_coherent_upd", 'update', $table, <<<SQL
                if not (new.assigned_user_id <=> old.assigned_user_id)
                    or not (new.responsible_sector_id <=> old.responsible_sector_id)
                    or not (new.status <=> old.status) then
                {$rule}
                end if;
                SQL);
        }

        MysqlTrigger::create('tasks_immutable_fields', 'update', 'tasks', <<<'SQL'
            if new.reference <> old.reference
                or new.event_id <> old.event_id
                or new.origin_sector_id <> old.origin_sector_id
                or not (new.source_occurrence_id <=> old.source_occurrence_id) then
                signal sqlstate '45000' set message_text = 'tasks: referência, evento, origem e ocorrência de origem não mudam';
            end if;
            SQL, 'follows tasks_assignee_coherent_upd');

        MysqlTrigger::create('occurrences_immutable_fields', 'update', 'occurrences', <<<'SQL'
            if new.reference <> old.reference
                or new.event_id <> old.event_id
                or new.origin_sector_id <> old.origin_sector_id then
                signal sqlstate '45000' set message_text = 'occurrences: referência, evento e origem não mudam';
            end if;
            SQL, 'follows occurrences_assignee_coherent_upd');

        MysqlTrigger::create('users_open_assignments_guard', 'update', 'users', <<<'SQL'
            if not (new.sector_id <=> old.sector_id) or new.status <> old.status then
                if exists (
                    select 1 from tasks t
                    where t.assigned_user_id = new.id and t.status <> 'COMPLETED'
                      and (new.status <> 'ACTIVE' or not (t.responsible_sector_id <=> new.sector_id))
                ) or exists (
                    select 1 from occurrences o
                    where o.assigned_user_id = new.id and o.status <> 'RESOLVED'
                      and (new.status <> 'ACTIVE' or not (o.responsible_sector_id <=> new.sector_id))
                ) then
                    signal sqlstate '45000' set message_text = 'users: usuário tem pendência/ocorrência aberta atribuída; reatribua antes de mudar setor ou inativar';
                end if;
            end if;
            SQL, 'precedes users_sector_matches_role_upd');
    }

    public function down(): void
    {
        if (Dialect::isMysql()) {
            foreach ([
                'users_open_assignments_guard', 'occurrences_immutable_fields', 'tasks_immutable_fields',
                'occurrences_assignee_coherent_ins', 'occurrences_assignee_coherent_upd',
                'tasks_assignee_coherent_ins', 'tasks_assignee_coherent_upd',
            ] as $trigger) {
                DB::unprepared("drop trigger if exists {$trigger}");
            }

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
