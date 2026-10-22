<?php

namespace Tests\Feature\Database;

use Illuminate\Support\Facades\DB;
use Tests\DatabaseTestCase;
use Tests\Support\PostgresSchemaSnapshot;

/**
 * O schema PostgreSQL gerado pelas migrations é idêntico à referência capturada antes do caminho MySQL
 * (HACKLAB-DEV-001, etapa 3; tests/Fixtures/pgsql-schema.json). Qualquer diferença reprova o CI.
 *
 * Regerar a referência só quando uma migration NOVA mudar o schema de propósito (nunca para "aceitar" uma diferença
 * causada pelo caminho MySQL): HACKLAB_WRITE_SCHEMA_REFERENCE=1 php artisan test --filter=PostgresSchemaReferenceTest
 */
class PostgresSchemaReferenceTest extends DatabaseTestCase
{
    public function test_postgresql_schema_matches_the_reference(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Referência do schema PostgreSQL: só roda em PostgreSQL.');
        }

        $current = PostgresSchemaSnapshot::capture(DB::connection());

        if (getenv('HACKLAB_WRITE_SCHEMA_REFERENCE') === '1') {
            file_put_contents(PostgresSchemaSnapshot::FIXTURE, PostgresSchemaSnapshot::encode($current));
            $this->markTestSkipped('Referência do schema regravada em tests/Fixtures/pgsql-schema.json.');
        }

        $this->assertFileExists(PostgresSchemaSnapshot::FIXTURE);
        $reference = json_decode(file_get_contents(PostgresSchemaSnapshot::FIXTURE), true, flags: JSON_THROW_ON_ERROR);

        foreach (array_keys($reference) as $section) {
            $this->assertEquals(
                $reference[$section],
                $current[$section] ?? null,
                "Schema PostgreSQL diferente da referência em \"{$section}\". O caminho PostgreSQL das migrations não pode mudar."
            );
        }
        $this->assertSame(array_keys($reference), array_keys($current));
    }
}
