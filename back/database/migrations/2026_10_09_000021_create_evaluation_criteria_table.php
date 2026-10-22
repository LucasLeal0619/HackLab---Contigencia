<?php

use App\Support\Database\Dialect;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Critérios numéricos de avaliação, por evento. Pesos não precisam somar 100 (o cálculo normaliza).
 * O travamento depois da primeira avaliação é regra de processo (EvaluationCriterionService).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('evaluation_criteria', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->restrictOnDelete();
            $table->string('name', 120);
            $table->text('description')->nullable();
            $table->decimal('min_score', 8, 2);
            $table->decimal('max_score', 8, 2);
            $table->decimal('weight', 8, 3);
            $table->unsignedSmallInteger('sort_order');
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['id', 'event_id']);
            $table->index(['event_id', 'active', 'sort_order']);
        });

        DB::statement('alter table evaluation_criteria add constraint evaluation_criteria_range_check check (max_score > min_score)');
        DB::statement('alter table evaluation_criteria add constraint evaluation_criteria_weight_check check (weight > 0)');
        DB::statement('alter table evaluation_criteria add constraint evaluation_criteria_sort_order_check check (sort_order >= 1)');
        DB::statement(Dialect::isMysql()
            ? 'create unique index evaluation_criteria_event_name_unique on evaluation_criteria (event_id, (lower(name)))'
            : 'create unique index evaluation_criteria_event_name_unique on evaluation_criteria (event_id, lower(name))');
    }

    public function down(): void
    {
        Schema::dropIfExists('evaluation_criteria');
    }
};
