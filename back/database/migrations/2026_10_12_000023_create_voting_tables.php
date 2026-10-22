<?php

use App\Support\Database\Dialect;
use App\Support\Database\MysqlParity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Votação pública: sessões, candidatas explícitas, elegíveis explícitos e votos append-only.
 *
 * Garantias no banco:
 * - tudo no mesmo evento (FKs compostas com event_id);
 * - no máximo uma sessão OPEN por evento (índice único parcial);
 * - voto só de elegível, só em candidata, um por usuário/sessão (FKs compostas + unique);
 * - candidatas/elegíveis só mudam em DRAFT; voto só entra com a sessão OPEN;
 * - votos nunca mudam nem são apagados; sessão nunca é apagada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('voting_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->restrictOnDelete();
            $table->string('name', 120);
            $table->string('status', 16)->default('DRAFT');
            $table->timestampTz('opened_at')->nullable();
            $table->timestampTz('closed_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->text('cancel_reason')->nullable();
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['id', 'event_id']);
            $table->index(['event_id', 'status']);
        });

        DB::statement("alter table voting_sessions add constraint voting_sessions_status_check check (status in ('DRAFT', 'OPEN', 'CLOSED', 'CANCELLED'))");
        DB::statement(<<<'SQL'
            alter table voting_sessions add constraint voting_sessions_timeline_check check (
                (status = 'DRAFT' and opened_at is null and closed_at is null and cancelled_at is null)
                or (status = 'OPEN' and opened_at is not null and closed_at is null and cancelled_at is null)
                or (status = 'CLOSED' and opened_at is not null and closed_at is not null and closed_at >= opened_at and cancelled_at is null)
                or (status = 'CANCELLED' and cancelled_at is not null and closed_at is null)
            )
            SQL);
        if (Dialect::isMysql()) {
            DB::statement('create unique index voting_sessions_event_name_unique on voting_sessions (event_id, (lower(name)))');
            // Índice funcional: só a sessão OPEN entra (as demais viram NULL, que não conflita).
            DB::statement("create unique index voting_sessions_one_open_per_event on voting_sessions ((if(status = 'OPEN', event_id, null)))");
        } else {
            DB::statement('create unique index voting_sessions_event_name_unique on voting_sessions (event_id, lower(name))');
            DB::statement("create unique index voting_sessions_one_open_per_event on voting_sessions (event_id) where status = 'OPEN'");
        }

        Schema::create('voting_session_teams', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('voting_session_id');
            $table->unsignedBigInteger('team_id');
            $table->timestampTz('created_at')->useCurrent();

            // Também é o alvo da FK de public_votes (o voto é em candidata da sessão).
            $table->unique(['voting_session_id', 'team_id']);
            $table->foreign(['voting_session_id', 'event_id'])->references(['id', 'event_id'])->on('voting_sessions')->restrictOnDelete();
            $table->foreign(['team_id', 'event_id'])->references(['id', 'event_id'])->on('teams')->restrictOnDelete();
            $table->index('team_id');
        });

        Schema::create('voting_eligibilities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('voting_session_id');
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestampTz('created_at')->useCurrent();

            // Também é o alvo da FK de public_votes (só vota quem é elegível).
            $table->unique(['voting_session_id', 'user_id']);
            $table->foreign(['voting_session_id', 'event_id'])->references(['id', 'event_id'])->on('voting_sessions')->restrictOnDelete();
            $table->index('user_id');
        });

        Schema::create('public_votes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('voting_session_id');
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('team_id');
            $table->timestampTz('cast_at');
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['voting_session_id', 'user_id']);
            $table->foreign(['voting_session_id', 'event_id'])->references(['id', 'event_id'])->on('voting_sessions')->restrictOnDelete();
            $table->foreign(['voting_session_id', 'team_id'])->references(['voting_session_id', 'team_id'])->on('voting_session_teams')->restrictOnDelete();
            $table->foreign(['voting_session_id', 'user_id'])->references(['voting_session_id', 'user_id'])->on('voting_eligibilities')->restrictOnDelete();
            $table->foreign(['team_id', 'event_id'])->references(['id', 'event_id'])->on('teams')->restrictOnDelete();
            $table->index(['voting_session_id', 'team_id']);
        });

        if (Dialect::isMysql()) {
            MysqlParity::pending('voting_sessions_guard');
            MysqlParity::pending('voting_session_teams_guard');
            MysqlParity::pending('voting_eligibilities_guard');
            MysqlParity::pending('public_votes_guard');

            return;
        }

        DB::unprepared(<<<'SQL'
            create or replace function voting_sessions_guard() returns trigger language plpgsql as $$
            begin
                if tg_op = 'DELETE' then
                    raise exception 'voting_sessions: sessão não é apagada (cancele em DRAFT)'
                        using errcode = 'check_violation';
                end if;

                if new.event_id <> old.event_id or new.created_by_user_id <> old.created_by_user_id then
                    raise exception 'voting_sessions: evento e criador não mudam'
                        using errcode = 'check_violation';
                end if;

                if old.status in ('CLOSED', 'CANCELLED') then
                    raise exception 'voting_sessions: sessão % é definitiva', old.status
                        using errcode = 'check_violation';
                end if;

                if old.opened_at is not null and new.opened_at is distinct from old.opened_at then
                    raise exception 'voting_sessions: opened_at não muda'
                        using errcode = 'check_violation';
                end if;

                if new.name <> old.name and old.status <> 'DRAFT' then
                    raise exception 'voting_sessions: nome só muda em DRAFT'
                        using errcode = 'check_violation';
                end if;

                if new.status <> old.status and not (
                    (old.status = 'DRAFT' and new.status in ('OPEN', 'CANCELLED'))
                    or (old.status = 'OPEN' and new.status in ('CLOSED', 'CANCELLED'))
                ) then
                    raise exception 'voting_sessions: transição % -> % não permitida', old.status, new.status
                        using errcode = 'check_violation';
                end if;

                if new.status = 'OPEN' and old.status = 'DRAFT' then
                    if not exists (select 1 from voting_session_teams where voting_session_id = new.id)
                        or not exists (select 1 from voting_eligibilities where voting_session_id = new.id) then
                        raise exception 'voting_sessions: abrir exige candidatas e elegíveis'
                            using errcode = 'check_violation';
                    end if;
                end if;

                if new.status = 'CANCELLED' and exists (select 1 from public_votes where voting_session_id = new.id) then
                    raise exception 'voting_sessions: sessão com votos não é cancelada'
                        using errcode = 'check_violation';
                end if;

                return new;
            end;
            $$;

            -- Candidatas e elegíveis: só entram/saem em DRAFT; nunca são alterados.
            create or replace function voting_configuration_guard() returns trigger language plpgsql as $$
            declare
                session_status text;
                target_session bigint;
            begin
                if tg_op = 'UPDATE' then
                    raise exception '%: registro não é alterado (retire e inclua)', tg_table_name
                        using errcode = 'check_violation';
                end if;

                target_session := case when tg_op = 'DELETE' then old.voting_session_id else new.voting_session_id end;
                select status into session_status from voting_sessions where id = target_session for share;

                if session_status is distinct from 'DRAFT' then
                    raise exception '%: configuração travada (sessão %)', tg_table_name, session_status
                        using errcode = 'check_violation';
                end if;

                if tg_op = 'DELETE' then
                    return old;
                end if;

                -- IFs aninhados: o PL/pgSQL não faz curto-circuito e new.team_id/new.user_id só existem numa tabela.
                if tg_table_name = 'voting_session_teams' then
                    if not exists (select 1 from teams where id = new.team_id and status = 'ACTIVE') then
                        raise exception 'voting_session_teams: equipe inativa não é candidata'
                            using errcode = 'check_violation';
                    end if;
                else
                    if not exists (
                        select 1 from users u join roles r on r.id = u.role_id
                        where u.id = new.user_id and u.status = 'ACTIVE' and r.code = 'VOTER'
                    ) then
                        raise exception 'voting_eligibilities: só usuário ACTIVE com perfil VOTER'
                            using errcode = 'check_violation';
                    end if;
                end if;

                return new;
            end;
            $$;

            -- Votos: append-only; só entram com a sessão OPEN e o eleitor ACTIVE + VOTER.
            create or replace function public_votes_guard() returns trigger language plpgsql as $$
            declare
                session_status text;
            begin
                if tg_op <> 'INSERT' then
                    raise exception 'public_votes: voto é definitivo (sem UPDATE/DELETE)'
                        using errcode = 'check_violation';
                end if;

                select status into session_status from voting_sessions where id = new.voting_session_id for share;

                if session_status is distinct from 'OPEN' then
                    raise exception 'public_votes: sessão % não recebe votos', session_status
                        using errcode = 'check_violation';
                end if;

                if not exists (
                    select 1 from users u join roles r on r.id = u.role_id
                    where u.id = new.user_id and u.status = 'ACTIVE' and r.code = 'VOTER'
                ) then
                    raise exception 'public_votes: só usuário ACTIVE com perfil VOTER vota'
                        using errcode = 'check_violation';
                end if;

                return new;
            end;
            $$;

            create trigger voting_sessions_guard before update or delete on voting_sessions
                for each row execute function voting_sessions_guard();
            create trigger voting_session_teams_guard before insert or update or delete on voting_session_teams
                for each row execute function voting_configuration_guard();
            create trigger voting_eligibilities_guard before insert or update or delete on voting_eligibilities
                for each row execute function voting_configuration_guard();
            create trigger public_votes_guard before insert or update or delete on public_votes
                for each row execute function public_votes_guard();
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('public_votes');
        Schema::dropIfExists('voting_eligibilities');
        Schema::dropIfExists('voting_session_teams');
        Schema::dropIfExists('voting_sessions');
        if (! Dialect::isMysql()) {
            DB::unprepared('drop function if exists public_votes_guard()');
            DB::unprepared('drop function if exists voting_configuration_guard()');
            DB::unprepared('drop function if exists voting_sessions_guard()');
        }
    }
};
