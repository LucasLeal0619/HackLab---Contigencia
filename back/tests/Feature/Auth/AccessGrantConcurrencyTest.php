<?php

namespace Tests\Feature\Auth;

use App\Domain\Auth\AccountAccessGrantService;
use App\Domain\Auth\AccountActivationService;
use App\Domain\Auth\Enums\AccessGrantSource;
use App\Domain\Auth\Enums\AccessGrantStatus;
use App\Domain\Users\Enums\RoleCode;
use App\Models\AccountAccessGrant;
use App\Models\Person;
use App\Models\User;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\ReconciliationScenario;
use Tests\RealConcurrencyTestCase;

/**
 * Liberação de acesso sob concorrência real (processos separados, PostgreSQL): o resultado é sempre coerente.
 */
class AccessGrantConcurrencyTest extends RealConcurrencyTestCase
{
    use ReconciliationScenario;

    private function grantedPerson(): array
    {
        Notification::fake();
        $this->buildReconciliationScenario();
        $person = $this->candidate('ana@teste.local', 'organization');
        $admin = $this->admin();
        $grant = app(AccountAccessGrantService::class)->grant($this->event, $person, RoleCode::Consultant, null, $admin, AccessGrantSource::Even3Organization);
        app(AccountActivationService::class)->request('ana@teste.local');

        return [$person, $grant, $admin, $this->activationTokenFor('ana@teste.local')];
    }

    public function test_two_completions_create_one_user_and_consume_the_grant_once(): void
    {
        /** @var Person $person */
        [$person, $grant, , $token] = $this->grantedPerson();

        $results = $this->runConcurrently([
            ['activation_complete', ['token' => $token, 'password' => 'senhaNova123']],
            ['activation_complete', ['token' => $token, 'password' => 'senhaNova123']],
        ]);

        $this->assertSame(1, $this->countWhere($results, fn ($r) => $r['ok']));
        $this->assertSame(1, User::query()->where('person_id', $person->id)->count());
        $grant = AccountAccessGrant::query()->find($grant->id);
        $this->assertSame(AccessGrantStatus::Consumed, $grant->status);
        $this->assertSame(User::query()->where('person_id', $person->id)->value('id'), $grant->user_id);
    }

    public function test_revoke_racing_activation_is_consistent(): void
    {
        [$person, $grant, $admin, $token] = $this->grantedPerson();

        $results = $this->runConcurrently([
            ['activation_complete', ['token' => $token, 'password' => 'senhaNova123']],
            ['grant_revoke', ['grant_id' => $grant->id, 'actor_id' => $admin->id]],
        ]);

        [$activation, $revoke] = $results;
        $this->assertTrue($activation['ok'] xor $revoke['ok'], 'exatamente uma das duas vence: '.json_encode($results));

        $grant = AccountAccessGrant::query()->find($grant->id);
        $users = User::query()->where('person_id', $person->id)->count();

        if ($activation['ok']) {
            $this->assertSame(AccessGrantStatus::Consumed, $grant->status);
            $this->assertSame(1, $users);
            $this->assertSame(422, $revoke['status']);
        } else {
            $this->assertSame(AccessGrantStatus::Revoked, $grant->status);
            $this->assertSame(0, $users);
            $this->assertSame(422, $activation['status']);
        }
    }
}
