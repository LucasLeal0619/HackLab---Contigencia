<?php

use App\Support\Database\Dialect;
use App\Support\Database\MysqlTrigger;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Avaliações técnicas (uma por jurado/equipe, só de atribuição existente) e notas por critério.
 *
 * Garantias no banco:
 * - avaliação ↔ atribuição (FK juror_id, team_id) e tudo no mesmo evento (FKs compostas);
 * - nota dentro da faixa do critério;
 * - avaliação SUBMITTED: notas imutáveis, comentário imutável, não volta para DRAFT.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('evaluations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('juror_id');
            $table->unsignedBigInteger('team_id');
            $table->string('status', 20)->default('DRAFT');
            $table->text('comments')->nullable();
            $table->timestampTz('submitted_at')->nullable();
            $table->text('revision_reason')->nullable();
            $table->timestampTz('revision_requested_at')->nullable();
            $table->foreignId('revision_requested_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['juror_id', 'team_id']);
            $table->unique(['id', 'event_id']);
            $table->foreign(['juror_id', 'team_id'])->references(['juror_id', 'team_id'])->on('juror_team_assignments')->restrictOnDelete();
            $table->foreign(['juror_id', 'event_id'])->references(['id', 'event_id'])->on('jurors')->restrictOnDelete();
            $table->foreign(['team_id', 'event_id'])->references(['id', 'event_id'])->on('teams')->restrictOnDelete();
            $table->index(['event_id', 'status']);
            $table->index(['team_id', 'status']);
        });

        DB::statement("alter table evaluations add constraint evaluations_status_check check (status in ('DRAFT', 'SUBMITTED', 'REVISION_REQUESTED'))");
        DB::statement("alter table evaluations add constraint evaluations_submitted_at_check check (status <> 'SUBMITTED' or submitted_at is not null)");

        Schema::create('evaluation_scores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('evaluation_id');
            $table->unsignedBigInteger('criterion_id');
            $table->decimal('score', 8, 2);
            $table->text('comment')->nullable();
            $table->timestamps();

            $table->unique(['evaluation_id', 'criterion_id']);
            $table->foreign(['evaluation_id', 'event_id'])->references(['id', 'event_id'])->on('evaluations')->restrictOnDelete();
            $table->foreign(['criterion_id', 'event_id'])->references(['id', 'event_id'])->on('evaluation_criteria')->restrictOnDelete();
            $table->index('criterion_id');
        });

        if (Dialect::isMysql()) {
            $this->createMysqlTriggers();

            return;
        }

        DB::unprepared(<<<'SQL'
            create or replace function evaluation_scores_guard() returns trigger language plpgsql as $$
            declare
                eval_status text;
                crit record;
            begin
                select status into eval_status from evaluations
                    where id = case when tg_op = 'DELETE' then old.evaluation_id else new.evaluation_id end;

                if eval_status = 'SUBMITTED' then
                    raise exception 'evaluation_scores: avaliação enviada não tem notas alteradas'
                        using errcode = 'check_violation';
                end if;

                if tg_op = 'DELETE' then
                    return old;
                end if;

                select min_score, max_score into crit from evaluation_criteria where id = new.criterion_id;

                if new.score < crit.min_score or new.score > crit.max_score then
                    raise exception 'evaluation_scores: nota % fora da faixa do critério (% a %)', new.score, crit.min_score, crit.max_score
                        using errcode = 'check_violation';
                end if;

                return new;
            end;
            $$;

            create or replace function evaluations_guard() returns trigger language plpgsql as $$
            begin
                if new.juror_id <> old.juror_id or new.team_id <> old.team_id or new.event_id <> old.event_id then
                    raise exception 'evaluations: jurado, equipe e evento não mudam'
                        using errcode = 'check_violation';
                end if;

                if old.status = 'SUBMITTED' and new.status = 'DRAFT' then
                    raise exception 'evaluations: avaliação enviada não volta para rascunho (use a revisão)'
                        using errcode = 'check_violation';
                end if;

                if old.status = 'SUBMITTED' and new.status = 'SUBMITTED' and new.comments is distinct from old.comments then
                    raise exception 'evaluations: avaliação enviada não tem comentário alterado'
                        using errcode = 'check_violation';
                end if;

                return new;
            end;
            $$;

            create trigger evaluation_scores_guard before insert or update or delete on evaluation_scores
                for each row execute function evaluation_scores_guard();
            create trigger evaluations_guard before update on evaluations
                for each row execute function evaluations_guard();
            SQL);
    }

    /**
     * Equivalentes MySQL (HACKLAB-DEV-001) de evaluation_scores_guard e evaluations_guard.
     */
    private function createMysqlTriggers(): void
    {
        $submitted = <<<'SQL'
            if eval_status = 'SUBMITTED' then
                signal sqlstate '45000' set message_text = 'evaluation_scores: avaliação enviada não tem notas alteradas';
            end if;
            SQL;
        $range = <<<'SQL'
            select min_score, max_score into min_v, max_v from evaluation_criteria where id = new.criterion_id;
            if new.score < min_v or new.score > max_v then
                set msg = concat('evaluation_scores: nota ', new.score, ' fora da faixa do critério (', min_v, ' a ', max_v, ')');
                signal sqlstate '45000' set message_text = msg;
            end if;
            SQL;
        $declare = "declare eval_status varchar(32);\ndeclare min_v decimal(8, 2);\ndeclare max_v decimal(8, 2);\ndeclare msg varchar(255);\n";

        foreach (['insert', 'update'] as $event) {
            MysqlTrigger::create('evaluation_scores_guard'.MysqlTrigger::SUFFIX[$event], $event, 'evaluation_scores', $declare
                ."select status into eval_status from evaluations where id = new.evaluation_id;\n{$submitted}\n{$range}");
        }
        MysqlTrigger::create('evaluation_scores_guard_del', 'delete', 'evaluation_scores',
            "declare eval_status varchar(32);\nselect status into eval_status from evaluations where id = old.evaluation_id;\n{$submitted}");

        MysqlTrigger::create('evaluations_guard', 'update', 'evaluations', <<<'SQL'
            if new.juror_id <> old.juror_id or new.team_id <> old.team_id or new.event_id <> old.event_id then
                signal sqlstate '45000' set message_text = 'evaluations: jurado, equipe e evento não mudam';
            end if;
            if old.status = 'SUBMITTED' and new.status = 'DRAFT' then
                signal sqlstate '45000' set message_text = 'evaluations: avaliação enviada não volta para rascunho (use a revisão)';
            end if;
            if old.status = 'SUBMITTED' and new.status = 'SUBMITTED' and not (new.comments <=> old.comments) then
                signal sqlstate '45000' set message_text = 'evaluations: avaliação enviada não tem comentário alterado';
            end if;
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('evaluation_scores');
        Schema::dropIfExists('evaluations');
        if (! Dialect::isMysql()) {
            DB::unprepared('drop function if exists evaluation_scores_guard()');
            DB::unprepared('drop function if exists evaluations_guard()');
        }
    }
};
