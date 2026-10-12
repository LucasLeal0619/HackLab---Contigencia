<?php

namespace Tests\Feature\Database;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MigrationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_migrations_run_on_the_postgres_test_database(): void
    {
        $this->assertSame('pgsql', DB::getDriverName());
        $this->assertSame('hacklab_test', DB::scalar('select current_database()'));

        $tables = [
            'migrations', 'sessions', 'cache', 'jobs', 'personal_access_tokens',
            'people', 'roles', 'permissions', 'role_permission', 'users', 'audit_logs',
            'events', 'event_days', 'sectors', 'meetings',
            'classes', 'participants', 'teams', 'team_members',
            'companies', 'company_representatives', 'challenges',
            'tasks', 'task_sectors', 'task_interactions',
            'occurrences', 'occurrence_sectors', 'occurrence_interactions',
            'jurors', 'juror_team_assignments', 'evaluation_criteria', 'evaluations', 'evaluation_scores',
            'voting_sessions', 'voting_session_teams', 'voting_eligibilities', 'public_votes', 'result_publications',
        ];

        foreach ($tables as $table) {
            $this->assertTrue(Schema::hasTable($table), "Tabela {$table} não foi criada.");
        }
    }

    public function test_tables_live_in_the_hacklab_schema_not_in_public(): void
    {
        $this->assertSame('hacklab', DB::scalar('select current_schema()'));

        $this->assertSame(0, (int) DB::scalar(
            "select count(*) from information_schema.tables where table_schema = 'public'"
        ));
        $this->assertSame(1, (int) DB::scalar(
            "select count(*) from information_schema.tables where table_schema = 'hacklab' and table_name = 'users'"
        ));
    }

    public function test_database_uses_utf8(): void
    {
        $this->assertSame('UTF8', DB::scalar("select current_setting('server_encoding')"));
    }
}
