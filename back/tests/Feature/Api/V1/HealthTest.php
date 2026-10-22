<?php

namespace Tests\Feature\Api\V1;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class HealthTest extends TestCase
{
    public function test_health_returns_ok_when_database_is_up(): void
    {
        $this->getJson('/api/v1/health')
            ->assertOk()
            ->assertJsonPath('data.status', 'ok')
            ->assertJsonPath('data.service', 'hacklab-api')
            ->assertJsonPath('data.api_version', 'v1')
            ->assertJsonPath('data.database', 'ok')
            ->assertJsonStructure(['data' => ['timestamp']]);
    }

    public function test_health_returns_503_without_leaking_details_when_database_is_down(): void
    {
        // Porta sem banco: a conexão falha na hora (a conexão padrão: PostgreSQL ou o MySQL local).
        $connection = config('database.default');
        config(["database.connections.{$connection}.host" => '127.0.0.1', "database.connections.{$connection}.port" => 1]);
        DB::purge($connection);

        $response = $this->getJson('/api/v1/health')
            ->assertStatus(503)
            ->assertJsonPath('data.status', 'degraded')
            ->assertJsonPath('data.database', 'unavailable');

        $this->assertStringNotContainsString('127.0.0.1', $response->getContent());
    }
}
