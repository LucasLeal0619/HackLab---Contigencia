<?php

use App\Support\Database\Dialect;
use App\Support\Database\MysqlTrigger;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Vínculo setorial da conta. Regra garantida no banco (trigger):
 * Gestor (MANAGER) e Editor (EDITOR) têm setor obrigatório; os demais perfis não têm setor.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('sector_id')->nullable()->after('role_id')->index()->constrained()->restrictOnDelete();
        });

        if (Dialect::isMysql()) {
            // users_sector_matches_role: Gestor/Editor exigem setor; os demais perfis não têm setor.
            $declare = "declare role_code varchar(32);\ndeclare msg varchar(255);\n";
            $rule = "select code into role_code from roles where id = new.role_id;\n"
                ."if role_code in ('MANAGER', 'EDITOR') and new.sector_id is null then\n"
                .MysqlTrigger::failWith("concat('Perfil ', role_code, ' exige setor (users.sector_id).')")."\n"
                ."end if;\n"
                ."if role_code not in ('MANAGER', 'EDITOR') and new.sector_id is not null then\n"
                .MysqlTrigger::failWith("concat('Perfil ', role_code, ' não tem vínculo setorial (users.sector_id deve ser nulo).')")."\n"
                .'end if;';

            MysqlTrigger::create('users_sector_matches_role_ins', 'insert', 'users', $declare.$rule);
            // "update of role_id, sector_id": no MySQL, quando um dos dois muda.
            MysqlTrigger::create('users_sector_matches_role_upd', 'update', 'users', $declare
                ."if not (new.role_id <=> old.role_id) or not (new.sector_id <=> old.sector_id) then\n{$rule}\nend if;");

            return;
        }

        DB::unprepared(<<<'SQL'
            create or replace function users_sector_matches_role() returns trigger language plpgsql as $$
            declare
                role_code text;
            begin
                select code into role_code from roles where id = new.role_id;

                if role_code in ('MANAGER', 'EDITOR') and new.sector_id is null then
                    raise exception 'Perfil % exige setor (users.sector_id).', role_code
                        using errcode = 'check_violation';
                end if;

                if role_code not in ('MANAGER', 'EDITOR') and new.sector_id is not null then
                    raise exception 'Perfil % não tem vínculo setorial (users.sector_id deve ser nulo).', role_code
                        using errcode = 'check_violation';
                end if;

                return new;
            end;
            $$;

            create trigger users_sector_matches_role
                before insert or update of role_id, sector_id on users
                for each row execute function users_sector_matches_role();
            SQL);
    }

    public function down(): void
    {
        if (Dialect::isMysql()) {
            DB::unprepared('drop trigger if exists users_sector_matches_role_ins');
            DB::unprepared('drop trigger if exists users_sector_matches_role_upd');
        } else {
            DB::unprepared('drop trigger if exists users_sector_matches_role on users');
            DB::unprepared('drop function if exists users_sector_matches_role()');
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('sector_id');
        });
    }
};
