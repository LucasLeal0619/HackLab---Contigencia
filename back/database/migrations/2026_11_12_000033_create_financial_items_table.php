<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->restrictOnDelete();
            $table->string('category', 120);
            $table->string('item', 180);
            $table->text('specification');
            $table->decimal('quantity', 12, 4);
            $table->string('unit', 24);
            $table->decimal('unit_price', 12, 4)->nullable();
            $table->string('supplier', 180)->nullable();
            $table->string('status', 80);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['event_id', 'category']);
            $table->index(['event_id', 'status']);
        });

        DB::statement('alter table financial_items add constraint financial_items_quantity_nonnegative_check check (quantity >= 0)');
        DB::statement('alter table financial_items add constraint financial_items_unit_price_nonnegative_check check (unit_price is null or unit_price >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_items');
    }
};
