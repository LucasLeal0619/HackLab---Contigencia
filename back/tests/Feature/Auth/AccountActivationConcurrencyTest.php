<?php

namespace Tests\Feature\Auth;

use App\Domain\Auth\AccountActivationService;
use App\Models\User;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\ActivationScenario;
use Tests\RealConcurrencyTestCase;

/**
 * Duas conclusões simultâneas do mesmo token (processos separados, PostgreSQL real): exatamente um User.
 */
class AccountActivationConcurrencyTest extends RealConcurrencyTestCase
{
    use ActivationScenario;

    public function test_same_token_completed_twice_at_once_creates_one_user(): void
    {
        Notification::fake();
        $this->buildActivationScenario();
        $person = $this->voterCandidate();
        app(AccountActivationService::class)->request('publico@teste.local');
        $token = $this->activationTokenFor('publico@teste.local');

        $results = $this->runConcurrently([
            ['activation_complete', ['token' => $token, 'password' => 'senhaNova123']],
            ['activation_complete', ['token' => $token, 'password' => 'senhaNova123']],
        ]);

        $this->assertSame(1, $this->countWhere($results, fn ($r) => $r['ok']));
        $this->assertSame(1, $this->countWhere($results, fn ($r) => ! $r['ok'] && $r['status'] === 422));
        $this->assertSame(1, User::query()->where('person_id', $person->id)->count());
    }
}
