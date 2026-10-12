<?php

namespace Tests\Feature\Audit;

use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditLogger;
use App\Domain\Users\Enums\RoleCode;
use App\Domain\Users\Enums\UserStatus;
use App\Models\AuditLog;
use App\Models\Event;
use App\Models\Sector;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\DatabaseTestCase;

/**
 * GET /audit-logs (+ /meta, /{id}): só Administrador, só leitura, paginado, filtros, ordem estável,
 * listagem enxuta e detalhe completo. Consultar auditoria não gera auditoria.
 */
class AuditLogApiTest extends DatabaseTestCase
{
    private User $adminUser;

    private Event $event;

    protected function setUp(): void
    {
        parent::setUp();
        $this->adminUser = $this->admin();
        $this->event = Event::factory()->create(['name' => 'Hackathon Auditado']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function logAt(string $iso, string $action = 'TEAM_CREATED', string $module = 'teams', ?User $actor = null, mixed $entity = null, ?array $before = null, ?array $after = null): AuditLog
    {
        Carbon::setTestNow(Carbon::parse($iso));

        return app(AuditLogger::class)->record($action, $module, "Log {$action}", entity: $entity, before: $before, after: $after, actor: $actor);
    }

    private function list(string $query = '', ?User $user = null)
    {
        return $this->actingAs($user ?? $this->adminUser)->getJson('/api/v1/audit-logs'.($query === '' ? '' : "?{$query}"));
    }

    public function test_only_administrator_reads_the_audit_trail(): void
    {
        $log = $this->logAt('2026-10-12T10:00:00Z');

        $this->list()->assertOk();
        $this->actingAs($this->adminUser)->getJson("/api/v1/audit-logs/{$log->id}")->assertOk();
        $this->actingAs($this->adminUser)->getJson('/api/v1/audit-logs/meta')->assertOk();

        $sector = Sector::factory()->for($this->event)->create();
        foreach ([
            $this->sectorUser(RoleCode::Manager, $sector),
            $this->sectorUser(RoleCode::Editor, $sector),
            $this->userWithRole(RoleCode::Consultant),
            $this->userWithRole(RoleCode::Juror),
            $this->userWithRole(RoleCode::Voter),
        ] as $user) {
            $this->list('', $user)->assertForbidden();
            $this->actingAs($user)->getJson("/api/v1/audit-logs/{$log->id}")->assertForbidden();
            $this->actingAs($user)->getJson('/api/v1/audit-logs/meta')->assertForbidden();
        }

        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/audit-logs')->assertUnauthorized();
    }

    public function test_inactive_administrator_is_blocked_by_the_existing_middleware(): void
    {
        $this->admin(); // continua existindo um Administrador ativo
        $this->adminUser->forceFill(['status' => UserStatus::Inactive])->save();

        $this->list()->assertForbidden();
    }

    public function test_there_is_no_route_to_create_change_or_delete_logs(): void
    {
        $log = $this->logAt('2026-10-12T10:00:00Z');

        $this->actingAs($this->adminUser)->postJson('/api/v1/audit-logs', ['action' => 'X'])->assertStatus(405);
        $this->actingAs($this->adminUser)->patchJson("/api/v1/audit-logs/{$log->id}", ['description' => 'x'])->assertStatus(405);
        $this->actingAs($this->adminUser)->putJson("/api/v1/audit-logs/{$log->id}", ['description' => 'x'])->assertStatus(405);
        $this->actingAs($this->adminUser)->deleteJson("/api/v1/audit-logs/{$log->id}")->assertStatus(405);

        $this->assertSame('Log TEAM_CREATED', $log->fresh()->description);
    }

    public function test_listing_is_lean_and_detail_is_complete(): void
    {
        $team = Team::factory()->for($this->event)->create(['name' => 'Equipe Atlas']);
        $log = $this->logAt('2026-10-12T10:00:00Z', AuditAction::TEAM_UPDATED, 'teams', $this->adminUser, $team, ['name' => 'Equipe Alpha', 'code' => null], ['name' => 'Equipe Atlas', 'code' => 'A1']);

        $item = $this->list()->assertOk()->json('data.0');

        $this->assertSame($log->id, $item['id']);
        $this->assertSame(['code' => 'TEAM_UPDATED', 'label' => 'Equipe alterada'], $item['action']);
        $this->assertSame(['code' => 'teams', 'label' => 'Equipes'], $item['module']);
        $this->assertSame(['id' => $this->event->id, 'name' => 'Hackathon Auditado'], $item['event']);
        $this->assertSame(['type' => 'Team', 'id' => (string) $team->id, 'label' => 'Equipe Atlas'], $item['entity']);
        $this->assertSame($this->adminUser->id, $item['actor']['user_id']);
        $this->assertSame(['code' => 'ADMINISTRATOR', 'name' => 'Administrador'], $item['actor']['role']);
        foreach (['before_data', 'after_data', 'changes', 'user_agent', 'ip_address'] as $hidden) {
            $this->assertArrayNotHasKey($hidden, $item);
        }

        $detail = $this->actingAs($this->adminUser)->withHeader('User-Agent', 'Detalhe/1.0')
            ->getJson("/api/v1/audit-logs/{$log->id}")->assertOk()->json('data');

        $this->assertSame('Equipe Alpha', $detail['before_data']['name']);
        $this->assertSame('Equipe Atlas', $detail['after_data']['name']);
        $this->assertEqualsCanonicalizing([
            ['path' => 'name', 'before' => 'Equipe Alpha', 'after' => 'Equipe Atlas', 'exists_before' => true, 'exists_after' => true],
            ['path' => 'code', 'before' => null, 'after' => 'A1', 'exists_before' => true, 'exists_after' => true],
        ], $detail['changes']);
        $this->assertArrayHasKey('ip_address', $detail);
        $this->assertArrayHasKey('user_agent', $detail);
        $this->assertSame($item['actor'], $detail['actor']);
    }

    public function test_actions_without_before_or_after_are_valid(): void
    {
        $log = $this->logAt('2026-10-12T10:00:00Z', AuditAction::LOGIN, 'auth', $this->adminUser, $this->adminUser);

        $this->actingAs($this->adminUser)->getJson("/api/v1/audit-logs/{$log->id}")
            ->assertOk()
            ->assertJsonPath('data.before_data', null)
            ->assertJsonPath('data.after_data', null)
            ->assertJsonPath('data.changes', [])
            ->assertJsonPath('data.event', null);
    }

    public function test_default_and_custom_pagination_with_metadata(): void
    {
        for ($i = 0; $i < 60; $i++) {
            $this->logAt('2026-10-12T10:00:00Z');
        }
        $total = AuditLog::query()->count();

        $this->list()->assertOk()
            ->assertJsonCount(50, 'data')
            ->assertJsonPath('meta.per_page', 50)
            ->assertJsonPath('meta.total', $total)
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.last_page', (int) ceil($total / 50));

        $this->list('per_page=7&page=2')->assertOk()
            ->assertJsonCount(7, 'data')
            ->assertJsonPath('meta.per_page', 7)
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('meta.last_page', (int) ceil($total / 7));

        $this->list('per_page=100')->assertOk()->assertJsonPath('meta.per_page', 100);
        $this->list('per_page=101')->assertUnprocessable()->assertJsonValidationErrors('per_page');
        $this->list('per_page=0')->assertUnprocessable()->assertJsonValidationErrors('per_page');
        $this->list('page=0')->assertUnprocessable()->assertJsonValidationErrors('page');
    }

    public function test_ordering_is_created_at_desc_then_id_desc(): void
    {
        $old = $this->logAt('2026-10-10T10:00:00Z');
        $sameA = $this->logAt('2026-10-12T10:00:00Z');
        $sameB = $this->logAt('2026-10-12T10:00:00Z');
        $newest = $this->logAt('2026-10-13T10:00:00Z');

        $ids = array_column($this->list('module=teams')->assertOk()->json('data'), 'id');

        $this->assertSame([$newest->id, $sameB->id, $sameA->id, $old->id], $ids);
    }

    public function test_filters_isolated_and_combined(): void
    {
        $otherEvent = Event::factory()->create();
        $sector = Sector::factory()->for($this->event)->create(['name' => 'Produção']);
        $editor = $this->sectorUser(RoleCode::Editor, $sector);
        $team = Team::factory()->for($this->event)->create(['name' => 'Equipe Órion']);
        $otherTeam = Team::factory()->for($otherEvent)->create(['name' => 'Equipe Vega']);

        $teamLog = $this->logAt('2026-10-01T12:00:00Z', AuditAction::TEAM_CREATED, 'teams', $editor, $team);
        $otherLog = $this->logAt('2026-10-05T12:00:00Z', AuditAction::TEAM_UPDATED, 'teams', $this->adminUser, $otherTeam);
        $loginLog = $this->logAt('2026-10-09T12:00:00Z', AuditAction::LOGIN, 'auth', $this->adminUser, $this->adminUser);

        $ids = fn (string $query) => array_column($this->list($query)->assertOk()->json('data'), 'id');

        $this->assertSame([$teamLog->id], $ids('search=%C3%B3rion'));               // entity_label, sem diferença de caixa
        $this->assertSame([$teamLog->id], $ids('search='.urlencode($editor->email))); // actor_email
        $this->assertSame([$loginLog->id], $ids('search=login'));                     // action/description
        $this->assertSame([$teamLog->id], $ids("event_id={$this->event->id}"));
        $this->assertSame([$otherLog->id, $teamLog->id], $ids('has_event=true'));
        $this->assertSame([$loginLog->id], $ids('has_event=false'));
        $this->assertSame([$teamLog->id], $ids("event_id={$this->event->id}&has_event=false")); // event_id prevalece
        $this->assertSame([$loginLog->id], $ids('module=auth'));
        $this->assertSame([$otherLog->id], $ids('action=TEAM_UPDATED'));
        $this->assertSame([$loginLog->id, $otherLog->id], $ids("actor_user_id={$this->adminUser->id}"));
        $this->assertSame([$teamLog->id], $ids('actor_role=EDITOR'));
        $this->assertSame([$teamLog->id], $ids("actor_sector_id={$sector->id}"));
        $this->assertSame([$loginLog->id], $ids('entity_type=User'));
        $this->assertSame([$teamLog->id], $ids("entity_type=Team&entity_id={$team->id}"));
        $this->assertSame([$loginLog->id, $otherLog->id], $ids('date_from=2026-10-05T00:00:00Z'));
        $this->assertSame([$otherLog->id, $teamLog->id], $ids('date_to=2026-10-05T12:00:00Z'));
        $this->assertSame([$otherLog->id], $ids('date_from=2026-10-02T00:00:00-03:00&date_to=2026-10-06T00:00:00-03:00'));
        $this->assertSame([$otherLog->id], $ids('module=teams&actor_role=ADMINISTRATOR&has_event=true'));
        $this->assertSame([], $ids("module=auth&event_id={$this->event->id}"));
        $this->assertCount(3, $ids('ip_address=127.0.0.1'));
    }

    public function test_invalid_filters_return_consistent_json_errors(): void
    {
        $this->list('date_from=2026-10-10T00:00:00Z&date_to=2026-10-01T00:00:00Z')
            ->assertUnprocessable()->assertJsonValidationErrors('date_to')->assertJsonStructure(['message', 'errors']);
        $this->list('date_from=ontem-talvez')->assertUnprocessable()->assertJsonValidationErrors('date_from');
        $this->list('has_event=talvez')->assertUnprocessable()->assertJsonValidationErrors('has_event');
        $this->list('actor_role=VALIDATOR')->assertUnprocessable()->assertJsonValidationErrors('actor_role');
        $this->list('event_id=abc')->assertUnprocessable()->assertJsonValidationErrors('event_id');
        $this->list('ip_address=999.1.1.1')->assertUnprocessable()->assertJsonValidationErrors('ip_address');
        $this->list('date_to=2026-10-01T00:00:00Z')->assertOk(); // date_to sozinho é válido
    }

    public function test_meta_returns_the_catalog(): void
    {
        $meta = $this->actingAs($this->adminUser)->getJson('/api/v1/audit-logs/meta')->assertOk()->json('data');

        $this->assertContains(['code' => 'tasks', 'label' => 'Pendências'], $meta['modules']);
        $this->assertContains(['code' => 'TASK_FORWARDED', 'label' => 'Pendência encaminhada', 'module' => 'tasks'], $meta['actions']);
        $this->assertNotContains('PUBLIC_VOTE_CAST', array_column($meta['actions'], 'code'));
    }

    public function test_reading_the_audit_trail_does_not_create_logs(): void
    {
        $log = $this->logAt('2026-10-12T10:00:00Z');
        $before = AuditLog::query()->count();

        $this->list()->assertOk();
        $this->list('search=x&module=teams')->assertOk();
        $this->actingAs($this->adminUser)->getJson("/api/v1/audit-logs/{$log->id}")->assertOk();
        $this->actingAs($this->adminUser)->getJson('/api/v1/audit-logs/meta')->assertOk();

        $this->assertSame($before, AuditLog::query()->count());
    }

    public function test_listing_does_not_query_per_row(): void
    {
        $sector = Sector::factory()->for($this->event)->create();
        $actors = [$this->adminUser, $this->sectorUser(RoleCode::Manager, $sector), $this->userWithRole(RoleCode::Consultant)];
        $events = [$this->event, Event::factory()->create(), Event::factory()->create()];

        for ($i = 0; $i < 60; $i++) {
            $event = $events[$i % 3];
            $this->logAt('2026-10-12T10:00:00Z', AuditAction::EVENT_UPDATED, 'events', $actors[$i % 3], $event);
        }

        $this->actingAs($this->adminUser);
        $this->adminUser->loadMissing('role.permissions');
        DB::enableQueryLog();
        $this->getJson('/api/v1/audit-logs?per_page=100')->assertOk()->assertJsonCount(60, 'data');
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        // Sessão/autorização + count + página + eventos (1 query para todos): constante, não 1 por linha.
        $this->assertLessThan(15, $queries, "Listagem fez {$queries} queries.");
    }
}
