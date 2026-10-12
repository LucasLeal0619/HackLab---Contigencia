<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 9: timezone IANA do evento (ex.: America/Sao_Paulo), usado para associar check-ins aos dias do evento.
 * Nulo até ser configurado; nunca deduzido de cidade/UF. A validação do identificador fica no backend.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->string('timezone', 64)->nullable()->after('location');
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn('timezone');
        });
    }
};
