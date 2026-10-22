<?php

use App\Support\Database\Dialect;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 8: contexto histórico em audit_logs (a tabela e o trigger somente-inserção da Fase 1 não mudam).
 *
 * - event_id: evento do objeto auditado (nulo em ações globais);
 * - actor_*: fotografia do ator no momento da ação (os IDs continuam sendo a identidade relacional);
 * - entity_label: rótulo legível da entidade no momento da ação.
 *
 * Logs anteriores ficam com essas colunas nulas: sem backfill (a tabela não aceita UPDATE).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->foreignId('event_id')->nullable()->after('actor_person_id')->constrained()->restrictOnDelete();
            $table->string('actor_name', 255)->nullable()->after('actor_person_id');
            $table->string('actor_email', 255)->nullable()->after('actor_name');
            $table->string('actor_role_code', 32)->nullable()->after('actor_email');
            $table->string('actor_role_name', 64)->nullable()->after('actor_role_code');
            $table->foreignId('actor_sector_id')->nullable()->after('actor_role_name')->constrained('sectors')->restrictOnDelete();
            $table->string('actor_sector_name', 120)->nullable()->after('actor_sector_id');
            $table->string('entity_label', 255)->nullable()->after('entity_id');

            $table->index(['event_id', 'created_at']);
            $table->index(['module', 'created_at']);
            $table->index(['actor_user_id', 'created_at']);
            $table->index(['actor_sector_id', 'created_at']);
        });
    }

    public function down(): void
    {
        if (Dialect::isMysql()) {
            // MySQL não apaga índice usado por chave estrangeira: as chaves saem antes dos índices.
            Schema::table('audit_logs', function (Blueprint $table) {
                $table->dropForeign(['event_id']);
                $table->dropForeign(['actor_sector_id']);
                $table->dropIndex(['event_id', 'created_at']);
                $table->dropIndex(['module', 'created_at']);
                $table->dropIndex(['actor_user_id', 'created_at']);
                $table->dropIndex(['actor_sector_id', 'created_at']);
                $table->dropColumn(['event_id', 'actor_sector_id', 'actor_name', 'actor_email', 'actor_role_code', 'actor_role_name', 'actor_sector_name', 'entity_label']);
            });

            return;
        }

        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropIndex(['event_id', 'created_at']);
            $table->dropIndex(['module', 'created_at']);
            $table->dropIndex(['actor_user_id', 'created_at']);
            $table->dropIndex(['actor_sector_id', 'created_at']);
            $table->dropConstrainedForeignId('event_id');
            $table->dropConstrainedForeignId('actor_sector_id');
            $table->dropColumn(['actor_name', 'actor_email', 'actor_role_code', 'actor_role_name', 'actor_sector_name', 'entity_label']);
        });
    }
};
