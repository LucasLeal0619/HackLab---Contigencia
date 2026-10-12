<?php

namespace Tests\Feature\Audit;

use App\Domain\Audit\AuditAction;
use App\Domain\Users\Enums\RoleCode;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Event;
use App\Models\Person;
use App\Models\Sector;
use App\Models\Team;
use App\Models\User;
use Tests\DatabaseTestCase;

/**
 * Fotografia histórica: ator (nome, e-mail, perfil, setor) e rótulo da entidade ficam como eram no momento
 * da ação. Evento é o do objeto auditado, nunca o do ator.
 */
class AuditSnapshotTest extends DatabaseTestCase
{
    private User $adminUser;

    private Event $event;

    protected function setUp(): void
    {
        parent::setUp();
        $this->adminUser = $this->admin();
        $this->event = Event::factory()->create();
    }

    private function lastLog(string $action): AuditLog
    {
        return AuditLog::query()->where('action', $action)->latest('id')->firstOrFail();
    }

    public function test_actor_snapshot_survives_role_sector_and_name_changes(): void
    {
        $production = Sector::factory()->for($this->event)->create(['name' => 'Produção']);
        $infra = Sector::factory()->for($this->event)->create(['name' => 'Infraestrutura']);
        $person = Person::factory()->create(['full_name' => 'João']);
        $joao = $this->sectorUser(RoleCode::Editor, $production);
        $joao->forceFill(['person_id' => $person->id])->save();

        $this->actingAs($joao)->postJson("/api/v1/events/{$this->event->id}/tasks", [
            'title' => 'Reservar equipamentos', 'description' => 'Projetores para o dia 2.',
        ])->assertCreated();
        $log = $this->lastLog(AuditAction::TASK_CREATED);

        // Depois: João vira Gestor de Infraestrutura, Produção é renomeado e a pessoa muda de nome.
        $this->actingAs($this->adminUser)->patchJson("/api/v1/users/{$joao->id}/role", ['role' => 'MANAGER', 'sector_id' => $infra->id])->assertOk();
        $this->actingAs($this->adminUser)->patchJson("/api/v1/sectors/{$production->id}", ['name' => 'Produção Central'])->assertOk();
        $this->actingAs($this->adminUser)->patchJson("/api/v1/people/{$person->id}", ['full_name' => 'João Pedro'])->assertOk();

        $actor = $this->actingAs($this->adminUser)->getJson("/api/v1/audit-logs/{$log->id}")->assertOk()->json('data.actor');

        $this->assertSame([
            'user_id' => $joao->id,
            'person_id' => $person->id,
            'name' => 'João',
            'email' => $joao->email,
            'role' => ['code' => 'EDITOR', 'name' => 'Editor'],
            'sector_id' => $production->id,
            'sector_name' => 'Produção',
        ], $actor);

        // O ID histórico do setor continua apontando para o mesmo setor (hoje "Produção Central").
        $this->assertSame('Produção Central', Sector::query()->find($actor['sector_id'])->name);
    }

    public function test_actors_without_sector_have_no_invented_sector(): void
    {
        $this->actingAs($this->adminUser)->postJson("/api/v1/events/{$this->event->id}/teams", ['name' => 'Equipe X'])->assertCreated();

        $log = $this->lastLog(AuditAction::TEAM_CREATED);
        $this->assertNull($log->actor_sector_id);
        $this->assertNull($log->actor_sector_name);
        $this->assertSame('ADMINISTRATOR', $log->actor_role_code);
    }

    public function test_entity_label_is_a_historical_snapshot(): void
    {
        $id = $this->actingAs($this->adminUser)->postJson("/api/v1/events/{$this->event->id}/teams", ['name' => 'Equipe Alpha'])->assertCreated()->json('data.id');
        $this->actingAs($this->adminUser)->patchJson("/api/v1/teams/{$id}", ['name' => 'Equipe Beta'])->assertOk();

        $this->assertSame('Equipe Alpha', $this->lastLog(AuditAction::TEAM_CREATED)->entity_label);
        $this->assertSame('Equipe Beta', $this->lastLog(AuditAction::TEAM_UPDATED)->entity_label);
        $this->assertSame('Equipe Beta', Team::query()->find($id)->name);
    }

    public function test_entity_labels_for_common_entities(): void
    {
        $sector = Sector::factory()->for($this->event)->create();
        $this->actingAs($this->adminUser)->postJson("/api/v1/events/{$this->event->id}/tasks", [
            'title' => 'Reservar equipamentos', 'description' => 'x', 'origin_sector_id' => $sector->id, 'responsible_sector_id' => $sector->id,
        ])->assertCreated();
        $this->actingAs($this->adminUser)->postJson('/api/v1/people', ['full_name' => 'Maria Silva'])->assertCreated();
        $companyId = $this->actingAs($this->adminUser)->postJson("/api/v1/events/{$this->event->id}/companies", ['name' => 'Empresa Alfa', 'type' => 'PARTNER'])
            ->assertCreated()->json('data.id');
        $this->actingAs($this->adminUser)->postJson("/api/v1/companies/{$companyId}/representatives", ['person' => ['full_name' => 'Ana Rep']])->assertCreated();

        $this->assertMatchesRegularExpression('/^PEN-\d+ · Reservar equipamentos$/', $this->lastLog(AuditAction::TASK_CREATED)->entity_label);
        // O representante foi cadastrado junto com a pessoa: dois PERSON_CREATED, um por pessoa.
        $this->assertSame(['Maria Silva', 'Ana Rep'], AuditLog::query()->where('action', AuditAction::PERSON_CREATED)->orderBy('id')->pluck('entity_label')->all());
        $this->assertSame('Empresa Alfa', $this->lastLog(AuditAction::COMPANY_CREATED)->entity_label);
        $this->assertSame('Ana Rep · Empresa Alfa', $this->lastLog(AuditAction::COMPANY_REPRESENTATIVE_ADDED)->entity_label);
        $this->assertSame($this->event->id, $this->lastLog(AuditAction::COMPANY_REPRESENTATIVE_ADDED)->event_id);
    }

    public function test_event_context_comes_from_the_audited_object(): void
    {
        $sector = Sector::factory()->for($this->event)->create();
        $manager = $this->sectorUser(RoleCode::Manager, $sector);

        // Event → o próprio id.
        $eventId = $this->actingAs($this->adminUser)->postJson('/api/v1/events', [
            'name' => 'Outro Hackathon', 'start_date' => '2026-11-10', 'end_date' => '2026-11-12',
        ])->assertCreated()->json('data.id');
        $this->assertSame($eventId, $this->lastLog(AuditAction::EVENT_CREATED)->event_id);

        // Team, Task, VotingSession → evento da entidade.
        $this->actingAs($this->adminUser)->postJson("/api/v1/events/{$this->event->id}/teams", ['name' => 'Equipe E'])->assertCreated();
        $this->assertSame($this->event->id, $this->lastLog(AuditAction::TEAM_CREATED)->event_id);

        $this->actingAs($manager)->postJson("/api/v1/events/{$this->event->id}/tasks", ['title' => 'T', 'description' => 'D', 'responsible_sector_id' => $sector->id])->assertCreated();
        $this->assertSame($this->event->id, $this->lastLog(AuditAction::TASK_CREATED)->event_id);

        $this->actingAs($this->adminUser)->postJson("/api/v1/events/{$eventId}/voting-sessions", ['name' => 'Sessão'])->assertCreated();
        $this->assertSame($eventId, $this->lastLog(AuditAction::VOTING_SESSION_CREATED)->event_id);

        // Ações globais: nunca herdam o evento do setor do ator.
        $this->app['auth']->forgetGuards();
        $this->fromSpa()->postJson('/api/v1/auth/login', ['email' => $manager->email, 'password' => 'password1'])->assertOk();
        $login = $this->lastLog(AuditAction::LOGIN);
        $this->assertNull($login->event_id);
        $this->assertSame($sector->id, $login->actor_sector_id); // o ator tem setor, mas o login não é do evento

        $this->actingAs($this->adminUser)->postJson('/api/v1/users', [
            'person' => ['full_name' => 'Nova Conta'], 'email' => 'nova@hacklab.test', 'password' => 'senhaForte1', 'role' => 'CONSULTANT',
        ])->assertCreated();
        $this->assertNull($this->lastLog(AuditAction::USER_CREATED)->event_id);
        $this->assertNull($this->lastLog(AuditAction::PERSON_CREATED)->event_id);
    }

    public function test_failed_login_has_no_actor_but_keeps_the_attempted_email(): void
    {
        $this->fromSpa()->postJson('/api/v1/auth/login', ['email' => 'ninguem@hacklab.test', 'password' => 'errada123'])->assertUnprocessable();

        $log = $this->lastLog(AuditAction::LOGIN_FAILED);
        $this->assertNull($log->actor_user_id);
        $this->assertNull($log->actor_name);
        $this->assertNull($log->event_id);
        $this->assertSame(['email' => 'ninguem@hacklab.test'], $log->after_data);
        $this->assertArrayNotHasKey('password', $log->after_data);

        $this->actingAs($this->adminUser)->getJson("/api/v1/audit-logs/{$log->id}")->assertOk()->assertJsonPath('data.actor', null);
    }

    public function test_company_representative_event_comes_from_the_company(): void
    {
        $company = Company::factory()->for($this->event)->create();

        $this->actingAs($this->adminUser)->postJson("/api/v1/companies/{$company->id}/representatives", ['person' => ['full_name' => 'Rep']])->assertCreated();

        $this->assertSame($this->event->id, $this->lastLog(AuditAction::COMPANY_REPRESENTATIVE_ADDED)->event_id);
    }
}
