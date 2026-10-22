<?php

namespace Tests\Feature\Database;

use App\Models\Person;
use App\Support\Database\CaseInsensitiveSearch;
use Illuminate\Database\MySqlConnection;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\DatabaseTestCase;

/**
 * Busca sem diferenciar maiúsculas e diferenciando acentos (HACKLAB-DEV-001): o mesmo comportamento no PostgreSQL
 * (produção) e no MySQL (desenvolvimento local), e o SQL do PostgreSQL idêntico ao de antes.
 */
class CaseInsensitiveSearchTest extends DatabaseTestCase
{
    public function test_postgresql_sql_is_identical_to_the_previous_ilike_operator(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Compara o SQL do PostgreSQL; no MySQL vale o teste da collation abaixo.');
        }

        $previous = DB::table('people')->where('full_name', 'ilike', '%a%')->orWhere('people.email', 'ilike', '%a%')->toSql();
        $current = DB::table('people')->whereILike('full_name', '%a%')->orWhereILike('people.email', '%a%')->toSql();

        $this->assertSame($previous, $current);
        $this->assertSame('select * from "people" where "full_name"::text ilike ? or "people"."email"::text ilike ?', $current);
    }

    public function test_mysql_sql_forces_an_accent_sensitive_case_insensitive_collation(): void
    {
        // Só compila o SQL: nenhuma conexão MySQL é aberta.
        $mysql = new MySqlConnection(fn () => throw new RuntimeException('sem conexão'), 'hacklab_dev', '', ['driver' => 'mysql']);

        $sql = $mysql->table('people')->whereILike('full_name', '%a%')->orWhereILike('people.email', '%a%')->toSql();

        $collation = CaseInsensitiveSearch::MYSQL_COLLATION;
        $this->assertSame("select * from `people` where `full_name` collate {$collation} like ? or `people`.`email` collate {$collation} like ?", $sql);
        $this->assertSame('utf8mb4_0900_as_ci', $collation);
    }

    public function test_search_ignores_case_and_respects_accents_through_the_api(): void
    {
        $admin = $this->admin();
        Person::factory()->create(['full_name' => 'MARIA DA SILVA', 'email' => 'maria@hacklab.test']);
        Person::factory()->create(['full_name' => 'José Pereira', 'email' => 'jose.pereira@hacklab.test']);

        $names = fn (string $search) => collect($this->actingAs($admin)->getJson('/api/v1/people?search='.urlencode($search))
            ->assertOk()->json('data'))->pluck('full_name')->all();

        $this->assertContains('MARIA DA SILVA', $names('maria'));
        $this->assertContains('MARIA DA SILVA', $names('Maria Da'));
        $this->assertContains('José Pereira', $names('josé'));
        $this->assertContains('José Pereira', $names('JOSÉ'));
        // Acento conta (como sempre foi no PostgreSQL): "jose" sem acento não acha "José" pelo nome.
        $this->assertNotContains('José Pereira', $names('jose p'));
    }
}
