<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Complemento 11.2: decisões do Administrador sobre candidatos vindos das inscrições externas.
 *
 * Pendência é derivada das inscrições (não é gravada). Aqui fica só a decisão explícita:
 * RESOLVED (com a entidade criada/vinculada) ou IGNORED. Uma decisão por integração + Person + fila.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_reconciliations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('event_integration_id');
            $table->foreignId('person_id')->constrained('people')->restrictOnDelete();
            $table->string('kind', 16);
            $table->string('status', 16);
            $table->string('resolution', 32)->nullable();
            $table->string('target_type', 32)->nullable();
            $table->unsignedBigInteger('target_id')->nullable();
            $table->foreignId('resolved_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('resolved_at');
            $table->text('note')->nullable();
            $table->timestamps();

            $table->unique(['event_integration_id', 'person_id', 'kind']);
            $table->foreign(['event_integration_id', 'event_id'])->references(['id', 'event_id'])->on('event_integrations')->restrictOnDelete();
            $table->index(['event_integration_id', 'kind', 'status']);
        });

        DB::statement("alter table integration_reconciliations add constraint integration_reconciliations_kind_check check (kind in ('JUROR', 'ORGANIZATION', 'COMPANY_GUEST'))");
        DB::statement("alter table integration_reconciliations add constraint integration_reconciliations_status_check check (status in ('RESOLVED', 'IGNORED'))");
        DB::statement("alter table integration_reconciliations add constraint integration_reconciliations_resolution_check check (resolution is null or resolution in ('JUROR_CREATED', 'REPRESENTATIVE_LINKED', 'IGNORED'))");
        DB::statement('alter table integration_reconciliations add constraint integration_reconciliations_target_check check ((target_type is null) = (target_id is null))');
        DB::statement("alter table integration_reconciliations add constraint integration_reconciliations_ignored_check check ((status = 'IGNORED') = (resolution = 'IGNORED'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_reconciliations');
    }
};
