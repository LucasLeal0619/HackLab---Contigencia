<?php

use App\Support\Database\Dialect;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Empresas (internas, por evento) e seus representantes (Persons).
 * "Aguardando desafio"/"Com desafio" não são status: são derivados dos desafios.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->restrictOnDelete();
            $table->string('name', 160);
            $table->string('legal_name')->nullable();
            // Só letras e dígitos, maiúsculas (compatível com CNPJ alfanumérico).
            $table->string('document', 32)->nullable();
            $table->string('segment', 120)->nullable();
            $table->string('type', 16);
            $table->text('description')->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('website')->nullable();
            $table->string('status', 16)->default('DRAFT');
            $table->timestamps();

            // Alvo da FK composta (company_id, event_id) de challenges.
            $table->unique(['id', 'event_id']);
            $table->index(['event_id', 'status']);
            $table->index(['event_id', 'type']);
        });

        DB::statement("alter table companies add constraint companies_type_check check (type in ('PARTICIPANT', 'PARTNER', 'SPONSOR', 'SUPPORT', 'OTHER'))");
        DB::statement("alter table companies add constraint companies_status_check check (status in ('DRAFT', 'CONFIRMED', 'INACTIVE'))");
        if (Dialect::isMysql()) {
            // 'c' = regex sensível a maiúsculas, como o "~" do PostgreSQL. NULL não conflita: "where document is not null" é implícito.
            DB::statement("alter table companies add constraint companies_document_normalized_check check (document is null or regexp_like(document, '^[A-Z0-9]+$', 'c'))");
            DB::statement('create unique index companies_event_name_unique on companies (event_id, (lower(name)))');
            DB::statement('create unique index companies_event_document_unique on companies (event_id, document)');
        } else {
            DB::statement("alter table companies add constraint companies_document_normalized_check check (document is null or document ~ '^[A-Z0-9]+$')");
            DB::statement('create unique index companies_event_name_unique on companies (event_id, lower(name))');
            DB::statement('create unique index companies_event_document_unique on companies (event_id, document) where document is not null');
        }

        Schema::create('company_representatives', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('person_id')->constrained('people')->restrictOnDelete();
            $table->string('title', 120)->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_primary')->default(false);
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['company_id', 'person_id']);
            $table->index('person_id');
        });

        // No máximo um representante principal ativo por empresa.
        DB::statement(Dialect::isMysql()
            ? 'create unique index company_representatives_one_primary on company_representatives ((if(is_primary and active, company_id, null)))'
            : 'create unique index company_representatives_one_primary on company_representatives (company_id) where is_primary and active');
    }

    public function down(): void
    {
        Schema::dropIfExists('company_representatives');
        Schema::dropIfExists('companies');
    }
};
