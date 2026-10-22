<?php

use App\Support\Database\Dialect;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Equipes e vínculos de participantes (com histórico).
 *
 * Garantias no banco:
 * - equipe e participante do vínculo são do mesmo evento (FKs compostas com event_id);
 * - um participante tem no máximo um vínculo ativo (índice único parcial);
 * - vínculo ativo não tem left_at; vínculo encerrado tem left_at.
 *
 * Sem challenge_id: o vínculo com desafio entra na Fase 4, junto com a tabela challenges.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('teams', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->restrictOnDelete();
            $table->string('name', 120);
            $table->string('code', 32)->nullable();
            $table->string('status', 16)->default('ACTIVE');
            $table->timestamps();

            $table->unique(['id', 'event_id']);
            $table->index(['event_id', 'status']);
        });

        DB::statement("alter table teams add constraint teams_status_check check (status in ('ACTIVE', 'INACTIVE'))");
        if (Dialect::isMysql()) {
            // MySQL não tem índice parcial: NULL nunca conflita em índice único, então "where code is not null" é implícito.
            DB::statement('create unique index teams_event_name_unique on teams (event_id, (lower(name)))');
            DB::statement('create unique index teams_event_code_unique on teams (event_id, (lower(code)))');
        } else {
            DB::statement('create unique index teams_event_name_unique on teams (event_id, lower(name))');
            DB::statement('create unique index teams_event_code_unique on teams (event_id, lower(code)) where code is not null');
        }

        Schema::create('team_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('team_id');
            $table->unsignedBigInteger('participant_id');
            $table->timestampTz('joined_at')->nullable();
            $table->timestampTz('left_at')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->foreign(['team_id', 'event_id'])->references(['id', 'event_id'])->on('teams')->restrictOnDelete();
            $table->foreign(['participant_id', 'event_id'])->references(['id', 'event_id'])->on('participants')->restrictOnDelete();
            $table->index(['team_id', 'active']);
            $table->index('participant_id');
        });

        DB::statement(Dialect::isMysql()
            // Índice funcional: só linhas ativas entram (as demais viram NULL, que não conflita).
            ? 'create unique index team_members_one_active_per_participant on team_members ((if(active, participant_id, null)))'
            : 'create unique index team_members_one_active_per_participant on team_members (participant_id) where active');
        DB::statement('alter table team_members add constraint team_members_active_left_check check ((active and left_at is null) or (not active and left_at is not null))');
        DB::statement('alter table team_members add constraint team_members_period_check check (left_at is null or joined_at is null or left_at >= joined_at)');
    }

    public function down(): void
    {
        Schema::dropIfExists('team_members');
        Schema::dropIfExists('teams');
    }
};
