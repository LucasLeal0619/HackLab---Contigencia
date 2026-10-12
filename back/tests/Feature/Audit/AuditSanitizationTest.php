<?php

namespace Tests\Feature\Audit;

use App\Domain\Audit\AuditLogger;
use App\Domain\Audit\AuditSanitizer;
use App\Models\AuditLog;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\DatabaseTestCase;

/**
 * Segredos nunca chegam ao banco nem à API (AuditSanitizer, recursivo); campos legítimos parecidos ficam.
 * Imutabilidade em duas camadas (model + trigger).
 */
class AuditSanitizationTest extends DatabaseTestCase
{
    private const SECRETS = [
        'password', 'password_confirmation', 'token', 'access_token', 'refresh_token', 'secret', 'client_secret',
        'cookie', 'authorization', 'api_key', 'apikey', 'remember_token', 'csrf', 'private_key', 'access_key',
        'Authorization', 'X-XSRF-TOKEN', 'apiKey', 'privateKey', 'session_cookie',
    ];

    /**
     * @return array<string, mixed>
     */
    private function payloadWithSecrets(): array
    {
        $secrets = array_fill_keys(self::SECRETS, 'VALOR-SECRETO');

        return $secrets + [
            'voting_session_id' => 7,
            'session_count' => 2,
            'email' => 'pessoa@hacklab.test',
            'status' => 'ACTIVE',
            'nested' => $secrets + ['voting_session_id' => 8, 'deeper' => [['token' => 'x', 'name' => 'ok']]],
        ];
    }

    public function test_secrets_are_removed_recursively_before_storage(): void
    {
        $log = app(AuditLogger::class)->record('TEST', 'tests', 'Sanitização.', before: $this->payloadWithSecrets(), after: $this->payloadWithSecrets());

        $stored = DB::table('audit_logs')->where('id', $log->id)->first();
        foreach ([$stored->before_data, $stored->after_data] as $json) {
            $this->assertStringNotContainsString('VALOR-SECRETO', $json);
            foreach (self::SECRETS as $key) {
                $this->assertStringNotContainsString("\"{$key}\"", $json);
            }
        }

        $after = $log->fresh()->after_data;
        $this->assertSame(7, $after['voting_session_id']);
        $this->assertSame(2, $after['session_count']);
        $this->assertSame(8, $after['nested']['voting_session_id']);
        $this->assertSame([['name' => 'ok']], $after['nested']['deeper']);
        $this->assertSame('pessoa@hacklab.test', $after['email']);
    }

    public function test_detail_api_sanitizes_again_defensively(): void
    {
        // Um log antigo, gravado sem o sanitizador atual (simulado com insert direto), não vaza pela API.
        $id = DB::table('audit_logs')->insertGetId([
            'action' => 'LEGACY', 'module' => 'tests', 'description' => 'Log antigo',
            'before_data' => json_encode(['password' => 'p', 'csrf' => 'c', 'ok' => 1]),
            'after_data' => json_encode(['nested' => ['api_key' => 'k', 'private_key' => 'pk'], 'voting_session_id' => 3]),
            'created_at' => now(),
        ]);

        $response = $this->actingAs($this->admin())->getJson("/api/v1/audit-logs/{$id}")->assertOk();

        $this->assertSame(['ok' => 1], $response->json('data.before_data'));
        $this->assertSame(['nested' => [], 'voting_session_id' => 3], $response->json('data.after_data'));
        foreach (['"password"', '"csrf"', '"api_key"', '"private_key"'] as $leak) {
            $this->assertStringNotContainsString($leak, $response->getContent());
        }
    }

    public function test_no_endpoint_echoes_session_cookie_csrf_hash_or_authorization(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->withHeader('Authorization', 'Bearer abc.def')->postJson('/api/v1/people', ['full_name' => 'Auditada'])->assertCreated();
        $log = AuditLog::query()->latest('id')->firstOrFail();

        foreach (["/api/v1/audit-logs/{$log->id}", '/api/v1/audit-logs'] as $url) {
            $content = $this->actingAs($admin)->getJson($url)->assertOk()->getContent();

            $this->assertStringNotContainsString($admin->password, $content);
            $this->assertStringNotContainsString('remember_token', $content);
            $this->assertStringNotContainsString('Bearer abc.def', $content);
            $this->assertStringNotContainsString('XSRF', $content);
            $this->assertStringNotContainsString('laravel_session', $content);
        }
    }

    public function test_session_alone_is_not_sensitive(): void
    {
        $this->assertFalse(AuditSanitizer::isSensitive('voting_session_id'));
        $this->assertFalse(AuditSanitizer::isSensitive('session'));
        $this->assertTrue(AuditSanitizer::isSensitive('session_cookie'));
        $this->assertTrue(AuditSanitizer::isSensitive('Api-Key'));
    }

    public function test_model_refuses_update_and_delete(): void
    {
        $log = app(AuditLogger::class)->record('TEST', 'tests', 'Imutável.');

        foreach ([fn () => $log->update(['description' => 'x']), fn () => $log->delete()] as $operation) {
            try {
                $operation();
                $this->fail('O model deveria recusar.');
            } catch (LogicException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame('Imutável.', $log->fresh()->description);
    }

    public function test_database_refuses_update_and_delete_of_new_columns_too(): void
    {
        $log = app(AuditLogger::class)->record('TEST', 'tests', 'Imutável.');

        foreach ([
            fn () => DB::table('audit_logs')->where('id', $log->id)->update(['actor_name' => 'Forjado']),
            fn () => DB::table('audit_logs')->where('id', $log->id)->update(['entity_label' => 'Forjado']),
            fn () => DB::table('audit_logs')->where('id', $log->id)->delete(),
        ] as $operation) {
            try {
                DB::transaction($operation);
                $this->fail('O banco deveria recusar.');
            } catch (QueryException $e) {
                $this->assertStringContainsString('somente inserção', $e->getMessage());
            }
        }
    }
}
