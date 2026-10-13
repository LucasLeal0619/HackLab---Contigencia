<?php

namespace Tests\Feature\Voting;

use App\Domain\Users\Enums\RoleCode;
use App\Domain\Voting\VotingSessionService;
use App\Models\Team;
use App\Models\User;
use App\Models\VotingEligibility;
use Tests\Concerns\ActivationScenario;
use Tests\RealConcurrencyTestCase;

/**
 * Dois "Adicionar votantes ativados" simultâneos (processos separados, PostgreSQL real):
 * sem duplicatas e sem erro; os mesmos votantes entram uma vez só.
 */
class PublicVoterEnrollmentConcurrencyTest extends RealConcurrencyTestCase
{
    use ActivationScenario;

    public function test_simultaneous_requests_do_not_duplicate_eligibilities(): void
    {
        $this->buildActivationScenario();
        $admin = $this->admin();
        foreach (range(1, 5) as $n) {
            $person = $this->person("publico{$n}@teste.local");
            User::factory()->role(RoleCode::Voter)->create(['person_id' => $person->id, 'email' => "publico{$n}@teste.local"]);
            $this->inscribe($person);
        }
        $service = app(VotingSessionService::class);
        $session = $service->create($this->event, 'Escolha do Público', $admin);
        $service->syncTeams($session, [Team::factory()->for($this->event)->create()->id]);

        $results = $this->runConcurrently([
            ['add_public_voters', ['session_id' => $session->id, 'actor_id' => $admin->id]],
            ['add_public_voters', ['session_id' => $session->id, 'actor_id' => $admin->id]],
        ]);

        $this->assertSame(2, $this->countWhere($results, fn ($r) => $r['ok']));
        $this->assertSame(5, array_sum(array_column($results, 'added')), 'cada votante entra uma vez, por um dos pedidos');
        $this->assertSame(5, VotingEligibility::query()->where('voting_session_id', $session->id)->count());
    }
}
