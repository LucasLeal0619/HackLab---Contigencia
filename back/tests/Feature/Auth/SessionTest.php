<?php

namespace Tests\Feature\Auth;

use App\Domain\Audit\AuditAction;
use App\Domain\Users\Enums\PermissionCode;
use App\Domain\Users\Enums\RoleCode;
use App\Domain\Users\Enums\UserStatus;
use Tests\DatabaseTestCase;

/**
 * /auth/me e /auth/logout.
 */
class SessionTest extends DatabaseTestCase
{
    public function test_me_returns_the_authenticated_user_with_person_role_and_permissions(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.id', $admin->id)
            ->assertJsonPath('data.email', $admin->email)
            ->assertJsonPath('data.person.full_name', $admin->person->full_name)
            ->assertJsonPath('data.role.code', 'ADMINISTRATOR')
            ->assertJsonPath('data.role.name', 'Administrador')
            ->assertJsonPath('data.sector', null)
            // Todas, exceto votes.cast (Administrador não vota por padrão).
            ->assertJsonCount(count(PermissionCode::cases()) - 1, 'data.permissions')
            ->assertJsonMissing(['votes.cast'])
            ->assertJsonMissingPath('data.password')
            ->assertJsonMissingPath('data.remember_token');
    }

    public function test_me_requires_authentication(): void
    {
        $this->getJson('/api/v1/auth/me')
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Não autenticado.');
    }

    public function test_session_of_a_user_inactivated_later_is_blocked(): void
    {
        $user = $this->userWithRole(RoleCode::Editor);
        $this->actingAs($user);

        $user->forceFill(['status' => UserStatus::Inactive])->save();

        $this->getJson('/api/v1/auth/me')
            ->assertForbidden()
            ->assertJsonPath('message', 'Conta inativa.');

        $this->assertGuest('web');
    }

    public function test_logout_ends_the_session(): void
    {
        $user = $this->userWithRole(RoleCode::Consultant, ['email' => 'consultor@hacklab.test']);

        $this->fromSpa()
            ->postJson('/api/v1/auth/login', ['email' => 'consultor@hacklab.test', 'password' => 'password1'])
            ->assertOk();

        $this->fromSpa()->postJson('/api/v1/auth/logout')->assertNoContent();

        $this->assertGuest('web');

        // Em produção cada requisição é um processo novo; no teste, as guardas guardam o usuário em memória.
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->assertDatabaseHas('audit_logs', ['action' => AuditAction::LOGOUT, 'actor_user_id' => $user->id]);
    }

    public function test_logout_requires_authentication(): void
    {
        $this->fromSpa()->postJson('/api/v1/auth/logout')->assertUnauthorized();
    }
}
