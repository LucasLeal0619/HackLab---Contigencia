<?php

use App\Support\Database\Dialect;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Turmas dos participantes, por evento. Nomes e quantidade vêm do cadastro.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('classes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->restrictOnDelete();
            $table->string('name', 120);
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index(['event_id', 'active']);
            // Alvo da FK composta (class_id, event_id) de participants.
            $table->unique(['id', 'event_id']);
        });

        DB::statement(Dialect::isMysql()
            ? 'create unique index classes_event_name_unique on classes (event_id, (lower(name)))'
            : 'create unique index classes_event_name_unique on classes (event_id, lower(name))');
    }

    public function down(): void
    {
        Schema::dropIfExists('classes');
    }
};
