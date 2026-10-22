<?php

use App\Support\Database\Dialect;
use App\Support\Database\MysqlTrigger;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Complemento 11.2: liberação de acesso decidida pelo Administrador ("esta Person pode ativar uma conta
 * com este perfil/setor"). A conta só nasce no primeiro acesso, com a senha criada pela própria pessoa.
 *
 * Garantias no banco:
 * - perfis permitidos: MANAGER, EDITOR (setor obrigatório) e CONSULTANT (sem setor) — trigger;
 * - setor do mesmo evento (FK composta);
 * - no máximo uma liberação PENDING por Person (o perfil do User é global);
 * - status e datas coerentes.
 *
 * O token de primeiro acesso passa a aceitar os perfis administrativos liberados.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_access_grants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->restrictOnDelete();
            $table->foreignId('person_id')->constrained('people')->restrictOnDelete();
            $table->foreignId('role_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('sector_id')->nullable();
            $table->string('status', 16)->default('PENDING');
            $table->string('source', 32);
            $table->foreignId('granted_by_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('revoked_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('consumed_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->timestamps();

            $table->foreign(['sector_id', 'event_id'])->references(['id', 'event_id'])->on('sectors')->restrictOnDelete();
            $table->index(['event_id', 'status']);
            $table->index('person_id');
        });

        DB::statement("alter table account_access_grants add constraint account_access_grants_status_check check (status in ('PENDING', 'CONSUMED', 'REVOKED'))");
        DB::statement("alter table account_access_grants add constraint account_access_grants_source_check check (source in ('EVEN3_ORGANIZATION'))");
        DB::statement("alter table account_access_grants add constraint account_access_grants_consumed_check check ((status = 'CONSUMED') = (consumed_at is not null) and (status = 'CONSUMED') = (user_id is not null))");
        DB::statement("alter table account_access_grants add constraint account_access_grants_revoked_check check ((status = 'REVOKED') = (revoked_at is not null))");
        DB::statement(Dialect::isMysql()
            ? "create unique index account_access_grants_one_pending on account_access_grants ((if(status = 'PENDING', person_id, null)))"
            : "create unique index account_access_grants_one_pending on account_access_grants (person_id) where status = 'PENDING'");

        if (Dialect::isMysql()) {
            $this->createMysqlRoleRulesTriggers();
        } else {
            $this->createPostgresRoleRulesTrigger();
        }

        DB::statement('alter table account_activation_tokens drop constraint account_activation_tokens_role_check');
        DB::statement("alter table account_activation_tokens add constraint account_activation_tokens_role_check check (intended_role in ('JUROR', 'VOTER', 'MANAGER', 'EDITOR', 'CONSULTANT'))");
    }

    private function createPostgresRoleRulesTrigger(): void
    {
        DB::unprepared(<<<'SQL'
            create or replace function account_access_grants_role_rules() returns trigger language plpgsql as $$
            declare
                role_code text;
            begin
                select code into role_code from roles where id = new.role_id;

                if role_code not in ('MANAGER', 'EDITOR', 'CONSULTANT') then
                    raise exception 'Liberação de acesso não aceita o perfil %.', role_code
                        using errcode = 'check_violation';
                end if;

                if role_code in ('MANAGER', 'EDITOR') and new.sector_id is null then
                    raise exception 'Perfil % exige setor na liberação de acesso.', role_code
                        using errcode = 'check_violation';
                end if;

                if role_code = 'CONSULTANT' and new.sector_id is not null then
                    raise exception 'Perfil CONSULTANT não tem setor.'
                        using errcode = 'check_violation';
                end if;

                return new;
            end;
            $$;

            create trigger account_access_grants_role_rules
                before insert or update of role_id, sector_id on account_access_grants
                for each row execute function account_access_grants_role_rules();
            SQL);
    }

    /**
     * Equivalente MySQL (HACKLAB-DEV-001) de account_access_grants_role_rules. A tabela sai na 000032.
     */
    private function createMysqlRoleRulesTriggers(): void
    {
        $rule = <<<'SQL'
            select code into role_code from roles where id = new.role_id;
            if role_code not in ('MANAGER', 'EDITOR', 'CONSULTANT') then
                set msg = concat('Liberação de acesso não aceita o perfil ', coalesce(role_code, '<NULL>'), '.');
                signal sqlstate '45000' set message_text = msg;
            end if;
            if role_code in ('MANAGER', 'EDITOR') and new.sector_id is null then
                set msg = concat('Perfil ', role_code, ' exige setor na liberação de acesso.');
                signal sqlstate '45000' set message_text = msg;
            end if;
            if role_code = 'CONSULTANT' and new.sector_id is not null then
                signal sqlstate '45000' set message_text = 'Perfil CONSULTANT não tem setor.';
            end if;
            SQL;
        $declare = "declare role_code varchar(32);\ndeclare msg varchar(255);\n";

        MysqlTrigger::create('account_access_grants_role_rules_ins', 'insert', 'account_access_grants', $declare.$rule);
        MysqlTrigger::create('account_access_grants_role_rules_upd', 'update', 'account_access_grants', $declare
            ."if not (new.role_id <=> old.role_id) or not (new.sector_id <=> old.sector_id) then\n{$rule}\nend if;");
    }

    public function down(): void
    {
        DB::statement('delete from account_activation_tokens where intended_role not in (\'JUROR\', \'VOTER\')');
        DB::statement('alter table account_activation_tokens drop constraint account_activation_tokens_role_check');
        DB::statement("alter table account_activation_tokens add constraint account_activation_tokens_role_check check (intended_role in ('JUROR', 'VOTER'))");

        if (! Dialect::isMysql()) {
            DB::unprepared('drop trigger if exists account_access_grants_role_rules on account_access_grants');
            DB::unprepared('drop function if exists account_access_grants_role_rules()');
        }
        Schema::dropIfExists('account_access_grants');
    }
};
