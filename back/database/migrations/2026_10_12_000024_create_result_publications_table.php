<?php

use App\Support\Database\Dialect;
use App\Support\Database\MysqlTrigger;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Publicação oficial dos resultados: snapshot imutável e versionado por evento.
 *
 * Garantias no banco:
 * - unique(event_id, version) e no máximo uma PUBLISHED por evento (índice único parcial);
 * - a sessão pública selecionada é do mesmo evento (FK composta) e está CLOSED na publicação;
 * - snapshot e dados da publicação nunca mudam; só PUBLISHED → REVOKED (com quem, quando e motivo);
 * - enquanto há PUBLISHED, atribuições jurado ↔ equipe e status de avaliações ficam travados.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('result_publications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('voting_session_id');
            $table->unsignedInteger('version');
            $table->string('status', 16)->default('PUBLISHED');
            $table->jsonb('snapshot');
            $table->foreignId('published_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestampTz('published_at');
            $table->foreignId('revoked_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('revoked_at')->nullable();
            $table->text('revoke_reason')->nullable();
            $table->timestamps();

            $table->unique(['event_id', 'version']);
            $table->foreign(['voting_session_id', 'event_id'])->references(['id', 'event_id'])->on('voting_sessions')->restrictOnDelete();
            $table->index('voting_session_id');
        });

        DB::statement("alter table result_publications add constraint result_publications_status_check check (status in ('PUBLISHED', 'REVOKED'))");
        DB::statement('alter table result_publications add constraint result_publications_version_check check (version >= 1)');
        DB::statement(<<<'SQL'
            alter table result_publications add constraint result_publications_revoked_check check (
                (status = 'PUBLISHED' and revoked_at is null and revoked_by_user_id is null and revoke_reason is null)
                or (status = 'REVOKED' and revoked_at is not null and revoked_by_user_id is not null and revoke_reason is not null)
            )
            SQL);
        DB::statement(Dialect::isMysql()
            ? "create unique index result_publications_one_published_per_event on result_publications ((if(status = 'PUBLISHED', event_id, null)))"
            : "create unique index result_publications_one_published_per_event on result_publications (event_id) where status = 'PUBLISHED'");

        if (Dialect::isMysql()) {
            $this->createMysqlTriggers();

            return;
        }

        DB::unprepared(<<<'SQL'
            create or replace function result_publications_guard() returns trigger language plpgsql as $$
            begin
                if tg_op = 'DELETE' then
                    raise exception 'result_publications: publicação é histórica (revogue, não apague)'
                        using errcode = 'check_violation';
                end if;

                if tg_op = 'INSERT' then
                    if not exists (select 1 from voting_sessions where id = new.voting_session_id and status = 'CLOSED') then
                        raise exception 'result_publications: a sessão de votação selecionada precisa estar CLOSED'
                            using errcode = 'check_violation';
                    end if;

                    return new;
                end if;

                if new.event_id <> old.event_id or new.voting_session_id <> old.voting_session_id
                    or new.version <> old.version or new.snapshot <> old.snapshot
                    or new.published_by_user_id <> old.published_by_user_id or new.published_at <> old.published_at then
                    raise exception 'result_publications: snapshot e dados da publicação são imutáveis'
                        using errcode = 'check_violation';
                end if;

                if old.status = 'REVOKED' then
                    raise exception 'result_publications: publicação revogada é definitiva'
                        using errcode = 'check_violation';
                end if;

                return new;
            end;
            $$;

            -- Resultado publicado congela o conjunto técnico contado (revogar a publicação libera).
            create or replace function technical_results_lock_guard() returns trigger language plpgsql as $$
            begin
                if tg_table_name = 'evaluations' and new.status is not distinct from old.status then
                    return new;
                end if;

                if exists (select 1 from result_publications where event_id = new.event_id and status = 'PUBLISHED') then
                    raise exception '%: resultado publicado; revogue a publicação antes de alterar o resultado técnico', tg_table_name
                        using errcode = 'check_violation';
                end if;

                return new;
            end;
            $$;

            create trigger result_publications_guard before insert or update or delete on result_publications
                for each row execute function result_publications_guard();
            create trigger juror_team_assignments_results_lock before insert or update on juror_team_assignments
                for each row execute function technical_results_lock_guard();
            create trigger evaluations_results_lock before update on evaluations
                for each row execute function technical_results_lock_guard();
            SQL);
    }

    /**
     * Equivalentes MySQL (HACKLAB-DEV-001) de result_publications_guard e technical_results_lock_guard.
     */
    private function createMysqlTriggers(): void
    {
        MysqlTrigger::create('result_publications_guard_del', 'delete', 'result_publications',
            MysqlTrigger::fail('result_publications: publicação é histórica (revogue, não apague)'));

        MysqlTrigger::create('result_publications_guard_ins', 'insert', 'result_publications', <<<'SQL'
            if not exists (select 1 from voting_sessions where id = new.voting_session_id and status = 'CLOSED') then
                signal sqlstate '45000' set message_text = 'result_publications: a sessão de votação selecionada precisa estar CLOSED';
            end if;
            SQL);

        MysqlTrigger::create('result_publications_guard_upd', 'update', 'result_publications', <<<'SQL'
            if new.event_id <> old.event_id or new.voting_session_id <> old.voting_session_id
                or new.version <> old.version or new.snapshot <> old.snapshot
                or new.published_by_user_id <> old.published_by_user_id or new.published_at <> old.published_at then
                signal sqlstate '45000' set message_text = 'result_publications: snapshot e dados da publicação são imutáveis';
            end if;
            if old.status = 'REVOKED' then
                signal sqlstate '45000' set message_text = 'result_publications: publicação revogada é definitiva';
            end if;
            SQL);

        // technical_results_lock_guard: resultado publicado congela o conjunto técnico contado.
        $locked = fn (string $table) => <<<SQL
            if exists (select 1 from result_publications where event_id = new.event_id and status = 'PUBLISHED') then
                signal sqlstate '45000' set message_text = '{$table}: resultado publicado; revogue a publicação antes de alterar o resultado técnico';
            end if;
            SQL;
        foreach (['insert', 'update'] as $event) {
            MysqlTrigger::create('juror_team_assignments_results_lock'.MysqlTrigger::SUFFIX[$event], $event, 'juror_team_assignments',
                $locked('juror_team_assignments'));
        }
        // Em evaluations só a troca de status conta; roda depois de evaluations_guard (ordem do PostgreSQL).
        MysqlTrigger::create('evaluations_results_lock', 'update', 'evaluations',
            "if not (new.status <=> old.status) then\n".$locked('evaluations')."\nend if;", 'follows evaluations_guard');
    }

    public function down(): void
    {
        if (Dialect::isMysql()) {
            // Os triggers de result_publications saem com a tabela; os das outras tabelas, explicitamente.
            foreach (['evaluations_results_lock', 'juror_team_assignments_results_lock_ins', 'juror_team_assignments_results_lock_upd'] as $trigger) {
                DB::unprepared("drop trigger if exists {$trigger}");
            }
            Schema::dropIfExists('result_publications');

            return;
        }

        DB::unprepared('drop trigger if exists evaluations_results_lock on evaluations');
        DB::unprepared('drop trigger if exists juror_team_assignments_results_lock on juror_team_assignments');
        Schema::dropIfExists('result_publications');
        DB::unprepared('drop function if exists technical_results_lock_guard()');
        DB::unprepared('drop function if exists result_publications_guard()');
    }
};
