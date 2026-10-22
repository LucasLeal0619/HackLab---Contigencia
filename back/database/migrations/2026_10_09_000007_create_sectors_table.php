<?php

use App\Support\Database\Dialect;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Setores da operação interna, por evento. Nomes vêm do cadastro, nunca do código.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sectors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->restrictOnDelete();
            $table->string('name', 120);
            $table->text('description')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index(['event_id', 'active']);
            // Alvo da FK composta (sector_id, event_id) das tabelas do evento (ex.: meetings).
            $table->unique(['id', 'event_id']);
        });

        // Nome único por evento, sem diferença de maiúsculas.
        DB::statement(Dialect::isMysql()
            ? 'create unique index sectors_event_name_unique on sectors (event_id, (lower(name)))'
            : 'create unique index sectors_event_name_unique on sectors (event_id, lower(name))');
    }

    public function down(): void
    {
        Schema::dropIfExists('sectors');
    }
};
