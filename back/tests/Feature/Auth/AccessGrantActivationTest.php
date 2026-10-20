<?php

namespace Tests\Feature\Auth;

use App\Domain\Auth\AccountActivationService;
use App\Domain\Auth\Enums\AccessGrantStatus;
use App\Domain\Users\Enums\RoleCode;
use App\Models\AccountAccessGrant;
use App\Models\Juror;
use App\Models\Sector;
use App\Models\User;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\AssertionFailedError;
use Tests\Concerns\ReconciliationScenario;
use Tests\DatabaseTestCase;

/**
 * Primeiro acesso com liberação de acesso (Complemento 11.2): Organização → liberação PENDING → pedido →
 * e-mail → conclusão → User com o perfil/setor da liberação → liberação CONSUMED.
 * Precedência: conta existente > liberação > Jurado ativo > Público.
 */
class AccessGrantActivationTest extends DatabaseTestCase
{
    use ReconciliationScenario;

    private Sector $sector;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->buildReconciliationScenario();
        $this->sector = Sector::factory()->for($this->event)->create(['name' => 'Infraestrutura']);
    }

    private function grantFor(int $personId, string $role, ?int $sectorId = null): int
    {
        return $this->actingAs($this->admin())
            ->postJson($this->base()."/reconciliation/organization/{$personId}/grant", ['role' => $role, 'sector_id' => $sectorId])
            ->assertCreated()->json('data.id');
    }

    private function activate(string $email): TestResponse
    {
        $this->app['auth']->forgetGuards();
        $this->requestActivation($email)->assertStatus(202);

        return $this->completeActivation($this->activationTokenFor($email));
    }

    public function test_organization_grant_becomes_a_user_with_role_and_sector(): void
    {
        $person = $this->candidate('ana@teste.local', 'organization');
        $grantId = $this->grantFor($person->id, 'MANAGER', $this->sector->id);

        $this->activate('ana@teste.local')->assertOk();

        $user = User::query()->where('person_id', $person->id)->sole();
        $this->assertSame(RoleCode::Manager, $user->role->code);
        $this->assertSame($this->sector->id, $user->sector_id);
        $grant = AccountAccessGrant::query()->find($grantId);
        $this->assertSame(AccessGrantStatus::Consumed, $grant->status);
        $this->assertNotNull($grant->consumed_at);
        $this->assertSame($user->id, $grant->user_id);

        $this->app['auth']->forgetGuards();
        $this->fromSpa()->postJson('/api/v1/auth/login', ['email' => 'ana@teste.local', 'password' => 'senhaNova123'])
            ->assertOk()->assertJsonPath('data.role.code', 'MANAGER')->assertJsonPath('data.sector.id', $this->sector->id);
    }

    public function test_consultant_grant_has_no_sector(): void
    {
        $person = $this->candidate('ana@teste.local', 'organization');
        $this->grantFor($person->id, 'CONSULTANT');

        $this->activate('ana@teste.local')->assertOk();

        $user = User::query()->where('person_id', $person->id)->sole();
        $this->assertSame(RoleCode::Consultant, $user->role->code);
        $this->assertNull($user->sector_id);
    }

    public function test_without_grant_organization_has_no_first_access(): void
    {
        $this->candidate('ana@teste.local', 'organization');

        $this->requestActivation('ana@teste.local')->assertStatus(202);

        $this->assertSame([], $this->activationTokensForSafe('ana@teste.local'));
        $this->assertSame(0, User::query()->count() - User::query()->whereHas('role', fn ($q) => $q->where('code', 'ADMINISTRATOR'))->count());
    }

    public function test_revoked_grant_invalidates_the_link_already_sent(): void
    {
        $person = $this->candidate('ana@teste.local', 'organization');
        $grantId = $this->grantFor($person->id, 'EDITOR', $this->sector->id);
        $this->requestActivation('ana@teste.local')->assertStatus(202);
        $token = $this->activationTokenFor('ana@teste.local');

        $this->actingAs($this->admin())->postJson("/api/v1/account-access-grants/{$grantId}/revoke")->assertOk();
        $this->app['auth']->forgetGuards();

        $this->completeActivation($token)->assertUnprocessable()->assertJsonValidationErrors('token');
        $this->assertSame(0, User::query()->where('person_id', $person->id)->count());
    }

    public function test_expired_link_does_not_activate(): void
    {
        $person = $this->candidate('ana@teste.local', 'organization');
        $this->grantFor($person->id, 'CONSULTANT');
        $this->app['auth']->forgetGuards();
        $this->requestActivation('ana@teste.local');
        $token = $this->activationTokenFor('ana@teste.local');

        $this->travel(AccountActivationService::EXPIRES_MINUTES + 1)->minutes();

        $this->completeActivation($token)->assertUnprocessable();
        $this->assertSame(AccessGrantStatus::Pending, AccountAccessGrant::query()->sole()->status);
    }

    public function test_existing_user_is_never_changed_by_a_grant_attempt(): void
    {
        $person = $this->candidate('ana@teste.local', 'organization');
        $user = User::factory()->role(RoleCode::Voter)->create(['person_id' => $person->id, 'email' => 'ana@teste.local']);

        $this->actingAs($this->admin())->postJson($this->base()."/reconciliation/organization/{$person->id}/grant", ['role' => 'CONSULTANT'])->assertUnprocessable();
        $this->app['auth']->forgetGuards();
        $this->requestActivation('ana@teste.local')->assertStatus(202);

        $this->assertSame([], $this->activationTokensForSafe('ana@teste.local'));
        $this->assertSame(RoleCode::Voter, $user->fresh()->role->code);
    }

    public function test_grant_has_precedence_over_public_registration(): void
    {
        $person = $this->candidate('ana@teste.local', 'organization');
        $this->inscribe($person, 'public');
        $this->grantFor($person->id, 'CONSULTANT');

        $this->activate('ana@teste.local')->assertOk();

        $this->assertSame(RoleCode::Consultant, User::query()->where('person_id', $person->id)->sole()->role->code);
    }

    public function test_grant_has_precedence_over_active_juror(): void
    {
        $person = $this->candidate('ana@teste.local', 'organization');
        Juror::factory()->for($this->event)->create(['person_id' => $person->id]);
        $this->grantFor($person->id, 'MANAGER', $this->sector->id);

        $this->activate('ana@teste.local')->assertOk();

        $this->assertSame(RoleCode::Manager, User::query()->where('person_id', $person->id)->sole()->role->code);
    }

    public function test_grant_with_inactive_sector_blocks_activation_until_admin_decides(): void
    {
        $person = $this->candidate('ana@teste.local', 'organization');
        $this->inscribe($person, 'public');
        $this->grantFor($person->id, 'EDITOR', $this->sector->id);
        $this->sector->forceFill(['active' => false])->save();

        $this->app['auth']->forgetGuards();
        $this->requestActivation('ana@teste.local')->assertStatus(202);

        $this->assertSame([], $this->activationTokensForSafe('ana@teste.local'), 'não cai para Votante');
    }

    /**
     * @return list<string>
     */
    private function activationTokensForSafe(string $email): array
    {
        try {
            return $this->activationTokensFor($email);
        } catch (AssertionFailedError) {
            return [];
        }
    }
}
