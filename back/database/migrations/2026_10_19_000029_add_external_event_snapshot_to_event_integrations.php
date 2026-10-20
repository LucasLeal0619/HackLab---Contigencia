<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Complemento 11.2: retrato mínimo do evento externo (título já existia; datas e quando foram lidos).
 * Permite comparar o evento interno com o da Even3 sem chamar a Even3. Sem payload bruto.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('event_integrations', function (Blueprint $table) {
            $table->date('external_start_date')->nullable()->after('external_event_title');
            $table->date('external_end_date')->nullable()->after('external_start_date');
            $table->timestampTz('external_metadata_synced_at')->nullable()->after('external_end_date');
        });
    }

    public function down(): void
    {
        Schema::table('event_integrations', function (Blueprint $table) {
            $table->dropColumn(['external_start_date', 'external_end_date', 'external_metadata_synced_at']);
        });
    }
};
