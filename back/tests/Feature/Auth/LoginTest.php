<?php

namespace Tests\Feature\Auth;

use App\Domain\Audit\AuditAction;
use App\Domain\Users\Enums\RoleCode;
use App\Models\AuditLog;
use App\Models\User;
use Tests\DatabaseTestCase;

class LoginTest extends DatabaseTestCase
{
    public function test_valid_login_authenticates_and_returns_the_user(): void
    {
        $user = $this->userWithRole(RoleCode::Manager, ['email' => 'gestor@hacklab.test']);

        $this->fromSpa()
            ->postJson('/api/v1/auth/login', ['email' => 'gestor@hacklab.test', 'password' => 'password1'])
            ->assertOk()
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.role.code', 'MANAGER')
            ->assertJsonPath('data.person.id', $user->person_id)
            ->assertJsonPath('data.sector.id', $user->sector_id)
            ->assertJsonPath('data.permissions', [
                'challenges.view', 'classes.view', 'companies.view', 'evaluations.progress.view', 'events.view', 'meetings.manage',
                'meetings.view', 'occurrences.comment', 'occurrences.create', 'occurrences.operate',
                'occurrences.route', 'occurrences.view', 'participants.view', 'people.view', 'results.view_published', 'sectors.manage',
                'sectors.view', 'tasks.comment', 'tasks.create', 'tasks.operate', 'tasks.route', 'tasks.view', 'teams.view',
            ])
            ->assertJsonMissingPath('data.password');

        $this->assertAuthenticatedAs($user, 'web');
        $this->assertNotNull($user->fresh()->last_login_at);
        $this->assertDatabaseHas('audit_logs', ['action' => AuditAction::LOGIN, 'actor_user_id' => $user->id]);
    }

    public function test_email_is_case_insensitive(): void
    {
        $this->userWithRole(RoleCode::Voter, ['email' => 'votante@hacklab.test']);

        $this->fromSpa()
            ->postJson('/api/v1/auth/login', ['email' => '  VOTANTE@HackLab.test ', 'password' => 'password1'])
            ->assertOk();
    }

    public function test_invalid_password_is_rejected_and_audited_without_the_password(): void
    {
        $this->userWithRole(RoleCode::Voter, ['email' => 'votante@hacklab.test']);

        $this->fromSpa()
            ->postJson('/api/v1/auth/login', ['email' => 'votante@hacklab.test', 'password' => 'senha-errada-123'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.email.0', 'E-mail ou senha inválidos.');

        $this->assertGuest('web');

        $log = AuditLog::query()->where('action', AuditAction::LOGIN_FAILED)->sole();
        $this->assertSame(['email' => 'votante@hacklab.test'], $log->after_data);
        $this->assertStringNotContainsString('senha-errada-123', json_encode($log->toArray()));
    }

    public function test_unknown_email_gets_the_same_error_as_wrong_password(): void
    {
        $this->fromSpa()
            ->postJson('/api/v1/auth/login', ['email' => 'ninguem@hacklab.test', 'password' => 'qualquer1'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.email.0', 'E-mail ou senha inválidos.');
    }

    public function test_inactive_user_cannot_log_in(): void
    {
        $user = User::factory()->inactive()->create(['email' => 'inativo@hacklab.test']);

        $this->fromSpa()
            ->postJson('/api/v1/auth/login', ['email' => 'inativo@hacklab.test', 'password' => 'password1'])
            ->assertForbidden()
            ->assertJsonPath('message', 'Conta inativa.');

        $this->assertGuest('web');
        $this->assertDatabaseHas('audit_logs', ['action' => AuditAction::LOGIN_BLOCKED_INACTIVE, 'entity_id' => (string) $user->id]);
    }

    public function test_login_validates_input(): void
    {
        $this->fromSpa()
            ->postJson('/api/v1/auth/login', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email', 'password']);
    }

    public function test_login_is_rate_limited(): void
    {
        foreach (range(1, 5) as $attempt) {
            $this->fromSpa()->postJson('/api/v1/auth/login', ['email' => 'alvo@hacklab.test', 'password' => "errada{$attempt}"]);
        }

        $this->fromSpa()
            ->postJson('/api/v1/auth/login', ['email' => 'alvo@hacklab.test', 'password' => 'errada6'])
            ->assertStatus(429);
    }

    public function test_login_outside_the_spa_has_no_session_and_is_refused(): void
    {
        $this->userWithRole(RoleCode::Voter, ['email' => 'votante@hacklab.test']);

        $this->postJson('/api/v1/auth/login', ['email' => 'votante@hacklab.test', 'password' => 'password1'])
            ->assertStatus(400);

        $this->assertGuest('web');
    }
}
