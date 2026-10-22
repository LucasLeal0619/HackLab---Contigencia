<?php

use App\Support\Database\Dialect;
use App\Support\Database\MysqlParity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Auditoria do backend. Somente inserção: um trigger recusa UPDATE e DELETE.
 *
 * Atores com restrictOnDelete: usuários e pessoas são inativados, não apagados.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('actor_person_id')->nullable()->constrained('people')->restrictOnDelete();
            $table->string('action', 64);
            $table->string('module', 64);
            $table->string('entity_type', 64)->nullable();
            $table->string('entity_id', 64)->nullable();
            $table->text('description');
            $table->jsonb('before_data')->nullable();
            $table->jsonb('after_data')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index('created_at');
            $table->index('actor_user_id');
            $table->index('action');
            $table->index(['module', 'entity_type', 'entity_id']);
        });

        if (Dialect::isMysql()) {
            MysqlParity::pending('audit_logs_no_update_delete');

            return;
        }

        DB::unprepared(<<<'SQL'
            create or replace function audit_logs_immutable() returns trigger language plpgsql as $$
            begin
                raise exception 'audit_logs é somente inserção (% recusado)', tg_op;
            end;
            $$;

            create trigger audit_logs_no_update_delete
                before update or delete on audit_logs
                for each row execute function audit_logs_immutable();
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        if (! Dialect::isMysql()) {
            DB::unprepared('drop function if exists audit_logs_immutable()');
        }
    }
};
