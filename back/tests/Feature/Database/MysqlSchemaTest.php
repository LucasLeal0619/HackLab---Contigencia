<?php

namespace Tests\Feature\Database;

use App\Models\Company;
use App\Models\Event;
use App\Models\Occurrence;
use App\Models\Person;
use App\Models\Sector;
use App\Models\Task;
use App\Support\Database\Dialect;
use App\Support\Database\MysqlParity;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\DatabaseTestCase;

/**
 * Estrutura MySQL (HACKLAB-DEV-001, etapa 4) contra um servidor MySQL 8 real: versão, conexão, índices únicos
 * (funcionais no lugar dos parciais do PostgreSQL), CHECKs com regex, collation, integridade referencial, referências
 * OCO-/PEN-, rollback e seeders. Só roda no MySQL (phpunit.mysql.xml); no PostgreSQL vale a referência do schema.
 */
class MysqlSchemaTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (! Dialect::isMysql()) {
            $this->markTestSkipped('Estrutura MySQL: só roda no MySQL (phpunit.mysql.xml).');
        }
    }

    public function test_server_and_connection_match_the_approved_setup(): void
    {
        $version = Dialect::ensureSupportedMysql();

        $this->assertTrue(version_compare(explode('-', $version)[0], Dialect::MYSQL_MINIMUM, '>='));
        $this->assertStringNotContainsStringIgnoringCase('mariadb', $version);
        $this->assertSame('READ-COMMITTED', DB::selectOne('select @@transaction_isolation as v')->v);
        $this->assertSame('utf8mb4_0900_as_cs', DB::selectOne('select @@collation_connection as v')->v);
        $this->assertSame('utf8mb4_0900_as_cs', DB::selectOne(
            "select collation_name as v from information_schema.columns where table_schema = database() and table_name = 'people' and column_name = 'full_name'"
        )->v);
    }

    public function test_every_postgresql_table_exists_in_mysql(): void
    {
        $reference = json_decode(file_get_contents(base_path('tests/Fixtures/pgsql-schema.json')), true);
        $mysql = array_map(fn ($r) => $r->t, DB::select("select table_name as t from information_schema.tables where table_schema = database() and table_type = 'BASE TABLE'"));

        $this->assertSame([], array_values(array_diff($reference['tables'], $mysql)), 'Tabelas do PostgreSQL ausentes no MySQL.');
        // Só no MySQL: o contador das referências (no PostgreSQL são sequences).
        $this->assertSame(['reference_counters'], array_values(array_diff($mysql, $reference['tables'])));
    }

    public function test_name_uniques_ignore_case_like_postgresql_lower_index_but_keep_accents(): void
    {
        $event = Event::factory()->create();
        Sector::factory()->create(['event_id' => $event->id, 'name' => 'Logística']);

        $this->assertDatabaseRefuses(fn () => Sector::factory()->create(['event_id' => $event->id, 'name' => 'LOGÍSTICA']));
        // Sem acento é outro nome (igual ao lower() do PostgreSQL).
        Sector::factory()->create(['event_id' => $event->id, 'name' => 'Logistica']);
        // Outro evento pode repetir.
        Sector::factory()->create(['name' => 'Logística']);

        $this->assertSame(3, Sector::query()->count());
    }

    public function test_partial_unique_indexes_have_functional_equivalents(): void
    {
        $event = Event::factory()->create();
        $user = $this->admin();
        $session = fn (string $status) => DB::table('voting_sessions')->insert([
            'event_id' => $event->id, 'name' => 'Sessão '.uniqid(), 'status' => $status, 'created_by_user_id' => $user->id,
            'opened_at' => $status === 'OPEN' ? now() : null, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $session('DRAFT');
        $session('DRAFT');
        $session('OPEN');
        $this->assertDatabaseRefuses(fn () => $session('OPEN'));

        $company = Company::factory()->create(['event_id' => $event->id]);
        $representative = fn (bool $primary, bool $active) => DB::table('company_representatives')->insert([
            'company_id' => $company->id, 'person_id' => Person::factory()->create()->id, 'is_primary' => $primary,
            'active' => $active, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $representative(true, true);
        $representative(true, false);
        $representative(false, true);
        $this->assertDatabaseRefuses(fn () => $representative(true, true));
    }

    public function test_check_constraints_reject_invalid_data(): void
    {
        $event = Event::factory()->create();

        $this->assertDatabaseRefuses(fn () => DB::table('people')->insert(['full_name' => 'X', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]));
        // Direto no banco: o model Company já normaliza o documento para maiúsculas antes de gravar.
        $company = fn (string $document) => DB::table('companies')->insert([
            'event_id' => $event->id, 'name' => 'Empresa '.$document, 'type' => 'PARTICIPANT', 'status' => 'DRAFT',
            'document' => $document, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->assertDatabaseRefuses(fn () => $company('12ab'));
        $company('12AB');
        $this->assertDatabaseRefuses(fn () => Occurrence::factory()->create(['event_id' => $event->id, 'reference' => 'oco-0001']));
        $this->assertDatabaseRefuses(fn () => DB::table('events')->where('id', $event->id)->update(['end_date' => '2000-01-01']));
        $this->assertDatabaseRefuses(fn () => DB::table('users')->where('id', $this->admin()->id)->update(['email' => 'Maiuscula@hacklab.test']));
    }

    public function test_composite_foreign_keys_block_cross_event_references(): void
    {
        $sectorOfOtherEvent = Sector::factory()->create();
        $event = Event::factory()->create();

        $this->assertDatabaseRefuses(fn () => Occurrence::factory()->create([
            'event_id' => $event->id,
            'origin_sector_id' => $sectorOfOtherEvent->id,
            'responsible_sector_id' => $sectorOfOtherEvent->id,
        ]));
    }

    public function test_references_are_generated_in_the_postgresql_format_and_rolled_back_with_the_transaction(): void
    {
        $event = Event::factory()->create();
        $first = Occurrence::factory()->create(['event_id' => $event->id])->refresh();
        $second = Occurrence::factory()->create(['event_id' => $event->id])->refresh();
        $task = Task::factory()->create(['event_id' => $event->id])->refresh();

        $this->assertMatchesRegularExpression('/^OCO-\d{4,}$/', $first->reference);
        $this->assertSame((int) substr($first->reference, 4) + 1, (int) substr($second->reference, 4));
        $this->assertMatchesRegularExpression('/^PEN-\d{4,}$/', $task->reference);

        $before = DB::table('reference_counters')->where('prefix', 'OCO')->value('counter_value');
        try {
            DB::transaction(function () use ($event) {
                Occurrence::factory()->create(['event_id' => $event->id]);
                throw new RuntimeException('desfaz');
            });
        } catch (RuntimeException) {
        }
        $this->assertSame($before, DB::table('reference_counters')->where('prefix', 'OCO')->value('counter_value'));
        $this->assertSame(2, Occurrence::query()->where('event_id', $event->id)->count());
    }

    public function test_search_ignores_case_and_respects_accents(): void
    {
        Person::factory()->create(['full_name' => 'MARIA DA SILVA']);
        Person::factory()->create(['full_name' => 'José Pereira']);

        $found = fn (string $like) => Person::query()->whereILike('full_name', $like)->pluck('full_name')->all();

        $this->assertSame(['MARIA DA SILVA'], $found('%maria%'));
        $this->assertSame(['José Pereira'], $found('%JOSÉ%'));
        $this->assertSame([], $found('%jose p%'));
        // Comparação comum (sem whereILike) diferencia maiúsculas, como no PostgreSQL.
        $this->assertSame(0, Person::query()->where('full_name', 'maria da silva')->count());
    }

    public function test_seeders_run_on_mysql(): void
    {
        Artisan::call('db:seed', ['--force' => true]);

        $this->assertGreaterThan(0, DB::table('roles')->count());
        $this->assertGreaterThan(0, DB::table('users')->count());
        $this->assertSame(0, DB::table('occurrences')->where('reference', 'not regexp', '^OCO-[0-9]{4,}$')->count());
    }

    public function test_postgresql_protections_still_pending_in_mysql_are_listed_explicitly(): void
    {
        $triggers = array_map(fn ($r) => $r->t, DB::select('select trigger_name as t from information_schema.triggers where trigger_schema = database()'));
        sort($triggers);

        $this->assertSame(['occurrences_reference_default', 'tasks_reference_default'], $triggers);
        $this->assertSame([], array_intersect(MysqlParity::PENDING, $triggers), 'Trigger implementado: remova-o de MysqlParity::PENDING.');

        $this->markTestIncomplete(count(MysqlParity::PENDING).' proteções do PostgreSQL ainda sem equivalente MySQL (etapa 5): '.implode(', ', MysqlParity::PENDING));
    }

    private function assertDatabaseRefuses(callable $statement): void
    {
        try {
            DB::transaction(fn () => $statement());
        } catch (QueryException) {
            $this->addToAssertionCount(1);

            return;
        }

        $this->fail('O banco aceitou um dado que deveria recusar.');
    }
}
