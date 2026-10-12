<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 9: integração com plataforma externa de inscrições (Even3, API V2).
 *
 * Garantias no banco:
 * - um evento externo alimenta um único evento interno; um evento interno tem no máximo uma integração por provider;
 * - tudo no mesmo evento (FKs compostas com event_id);
 * - entrada identificada por external_ticket_id; inscrição única por id externo na integração (uma pessoa pode
 *   ter várias inscrições);
 * - um check-in por disparo de webhook; no máximo um sync RUNNING por integração;
 * - recibo de webhook único por disparo.
 *
 * Nenhuma tabela guarda token, segredo ou payload completo.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Alvo das FKs compostas de attendance_records.
        DB::statement('create unique index event_days_id_event_unique on event_days (id, event_id)');

        Schema::create('event_integrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->restrictOnDelete();
            $table->string('provider', 32);
            $table->string('external_event_id', 64);
            $table->string('external_event_title')->nullable();
            $table->string('api_version', 8);
            $table->string('status', 16)->default('ACTIVE');
            $table->timestampTz('last_connection_checked_at')->nullable();
            $table->timestampTz('last_sync_at')->nullable();
            $table->string('last_sync_status', 16)->nullable();
            $table->string('last_error_code', 64)->nullable();
            $table->timestampTz('last_error_at')->nullable();
            $table->timestamps();

            $table->unique(['event_id', 'provider']);
            $table->unique(['provider', 'external_event_id']);
            $table->unique(['id', 'event_id']);
        });

        DB::statement("alter table event_integrations add constraint event_integrations_provider_check check (provider in ('EVEN3'))");
        DB::statement("alter table event_integrations add constraint event_integrations_api_version_check check (api_version in ('V2'))");
        DB::statement("alter table event_integrations add constraint event_integrations_status_check check (status in ('ACTIVE', 'DISABLED'))");
        DB::statement("alter table event_integrations add constraint event_integrations_last_sync_status_check check (last_sync_status is null or last_sync_status in ('SUCCESS', 'PARTIAL', 'FAILED'))");

        Schema::create('external_ticket_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('event_integration_id');
            $table->string('external_ticket_id', 64);
            $table->string('external_name');
            $table->string('internal_category', 32)->nullable();
            $table->boolean('active')->default(true);
            $table->timestampTz('last_seen_at')->nullable();
            $table->timestamps();

            $table->unique(['event_integration_id', 'external_ticket_id']);
            $table->unique(['id', 'event_id']);
            $table->foreign(['event_integration_id', 'event_id'])->references(['id', 'event_id'])->on('event_integrations')->restrictOnDelete();
        });

        DB::statement("alter table external_ticket_types add constraint external_ticket_types_category_check check (internal_category is null or internal_category in ('PARTICIPANT', 'ORGANIZATION', 'COMPANY_GUEST', 'JUROR', 'PUBLIC'))");
        DB::statement('create index external_ticket_types_name_index on external_ticket_types (event_integration_id, lower(external_name))');

        Schema::create('event_registrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('event_integration_id');
            $table->foreignId('person_id')->constrained('people')->restrictOnDelete();
            $table->string('external_attendee_id', 64)->nullable();
            $table->string('external_person_id', 64)->nullable();
            $table->string('external_registration_id', 64)->nullable();
            $table->unsignedBigInteger('external_ticket_type_id')->nullable();
            $table->string('external_category_label')->nullable();
            $table->string('status', 16)->default('UNKNOWN');
            $table->timestampTz('status_changed_at')->nullable();
            $table->string('check_in_code', 64)->nullable();
            $table->timestampTz('registered_at')->nullable();
            $table->string('source_name');
            $table->string('source_badge_name')->nullable();
            $table->string('source_email')->nullable();
            $table->string('source_phone', 32)->nullable();
            $table->string('source_document', 32)->nullable();
            $table->boolean('identity_mismatch')->default(false);
            $table->timestampTz('last_seen_at')->nullable();
            $table->timestampTz('last_synced_at')->nullable();
            $table->timestamps();

            // A mesma Person pode ter várias inscrições externas na mesma integração (sem unique por pessoa):
            // a identidade da inscrição são os IDs externos (índices únicos parciais abaixo).
            $table->index(['event_integration_id', 'person_id']);
            $table->unique(['id', 'event_id']);
            $table->foreign(['event_integration_id', 'event_id'])->references(['id', 'event_id'])->on('event_integrations')->restrictOnDelete();
            $table->foreign(['external_ticket_type_id', 'event_id'])->references(['id', 'event_id'])->on('external_ticket_types')->restrictOnDelete();
            $table->index(['event_id', 'status']);
            $table->index('person_id');
        });

        DB::statement("alter table event_registrations add constraint event_registrations_status_check check (status in ('PENDING', 'CONFIRMED', 'CANCELLED', 'UNKNOWN'))");
        DB::statement('create unique index event_registrations_attendee_unique on event_registrations (event_integration_id, external_attendee_id) where external_attendee_id is not null');
        DB::statement('create unique index event_registrations_registration_unique on event_registrations (event_integration_id, external_registration_id) where external_registration_id is not null');

        Schema::create('integration_webhook_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_integration_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('external_delivery_id', 128);
            $table->integer('event_type_id')->nullable();
            $table->string('event_type_name');
            $table->string('payload_hash', 64);
            $table->string('status', 16)->default('RECEIVED');
            $table->timestampTz('received_at');
            $table->timestampTz('processed_at')->nullable();
            $table->string('error_code', 64)->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->unique(['event_integration_id', 'external_delivery_id']);
            $table->index(['status', 'received_at']);
        });

        DB::statement("alter table integration_webhook_receipts add constraint integration_webhook_receipts_status_check check (status in ('RECEIVED', 'PROCESSED', 'IGNORED', 'FAILED'))");
        DB::statement('create unique index integration_webhook_receipts_orphan_unique on integration_webhook_receipts (external_delivery_id) where event_integration_id is null');

        Schema::create('attendance_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('event_integration_id');
            $table->unsignedBigInteger('event_registration_id');
            $table->unsignedBigInteger('event_day_id')->nullable();
            $table->string('kind', 16);
            $table->string('external_activity_id', 64)->nullable();
            $table->timestampTz('checked_in_at')->nullable();
            $table->timestampTz('observed_at');
            $table->string('source', 16);
            $table->foreignId('webhook_receipt_id')->nullable()->constrained('integration_webhook_receipts')->restrictOnDelete();
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign(['event_integration_id', 'event_id'])->references(['id', 'event_id'])->on('event_integrations')->restrictOnDelete();
            $table->foreign(['event_registration_id', 'event_id'])->references(['id', 'event_id'])->on('event_registrations')->restrictOnDelete();
            $table->foreign(['event_day_id', 'event_id'])->references(['id', 'event_id'])->on('event_days')->restrictOnDelete();
            $table->index(['event_registration_id', 'event_day_id']);
            $table->index(['event_id', 'event_day_id']);
        });

        DB::statement("alter table attendance_records add constraint attendance_records_kind_check check (kind in ('EVENT', 'ACTIVITY'))");
        DB::statement("alter table attendance_records add constraint attendance_records_source_check check (source in ('WEBHOOK', 'API'))");
        DB::statement('create unique index attendance_records_receipt_unique on attendance_records (webhook_receipt_id) where webhook_receipt_id is not null');

        Schema::create('integration_sync_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_integration_id')->constrained()->restrictOnDelete();
            $table->string('scope', 16);
            $table->string('trigger', 16);
            $table->string('status', 16)->default('RUNNING');
            $table->foreignId('started_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('started_at');
            $table->timestampTz('finished_at')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->jsonb('counters')->default('{}');
            $table->jsonb('warnings')->nullable();
            $table->string('error_code', 64)->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['event_integration_id', 'started_at']);
        });

        DB::statement("alter table integration_sync_runs add constraint integration_sync_runs_scope_check check (scope in ('EVENT', 'TICKETS', 'ATTENDEES', 'ALL'))");
        DB::statement("alter table integration_sync_runs add constraint integration_sync_runs_trigger_check check (trigger in ('MANUAL', 'SCHEDULED', 'RECONCILIATION'))");
        DB::statement("alter table integration_sync_runs add constraint integration_sync_runs_status_check check (status in ('RUNNING', 'SUCCESS', 'PARTIAL', 'FAILED'))");
        DB::statement("alter table integration_sync_runs add constraint integration_sync_runs_finished_check check ((status = 'RUNNING') = (finished_at is null))");
        DB::statement("create unique index integration_sync_runs_one_running on integration_sync_runs (event_integration_id) where status = 'RUNNING'");
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_sync_runs');
        Schema::dropIfExists('attendance_records');
        Schema::dropIfExists('integration_webhook_receipts');
        Schema::dropIfExists('event_registrations');
        Schema::dropIfExists('external_ticket_types');
        Schema::dropIfExists('event_integrations');
        DB::statement('drop index if exists event_days_id_event_unique');
    }
};
