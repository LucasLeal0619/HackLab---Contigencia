<?php

namespace Tests\Feature\Demands;

use App\Domain\Users\Enums\RoleCode;
use App\Models\Event;
use App\Models\Sector;
use App\Models\Task;
use Tests\Concerns\DemandScenario;
use Tests\DatabaseTestCase;

/**
 * Destinos de encaminhamento/criação de demandas: setores ativos do evento, só para quem tem "route".
 * A listagem normal de setores continua escopada ao próprio setor do Gestor/Editor.
 */
class DemandRoutingSectorsTest extends DatabaseTestCase
{
    use DemandScenario;

    private Sector $inactive;

    private Sector $otherEventSector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildDemandScenario();
        $this->inactive = Sector::factory()->for($this->event)->create(['name' => 'Setor Inativo', 'active' => false]);
        $this->otherEventSector = Sector::factory()->for(Event::factory())->create(['name' => 'Setor Alheio']);
    }

    private function url(string $kind, ?Event $event = null): string
    {
        return '/api/v1/events/'.($event ?? $this->event)->id."/{$kind}/routing-sectors";
    }

    public function test_manager_gets_all_active_sectors_of_own_event_for_both_kinds(): void
    {
        foreach (['tasks', 'occurrences'] as $kind) {
            $response = $this->actingAs($this->manager('A'))->getJson($this->url($kind))->assertOk();

            $this->assertSame(['Setor A', 'Setor B', 'Setor C', 'Setor D'], array_column($response->json('data'), 'name'));
            $this->assertSame(['id', 'name'], array_keys($response->json('data.0')), 'só id e nome');
        }
    }

    public function test_inactive_and_other_event_sectors_do_not_leak(): void
    {
        $names = array_column($this->actingAs($this->adminUser)->getJson($this->url('tasks'))->assertOk()->json('data'), 'name');

        $this->assertNotContains('Setor Inativo', $names);
        $this->assertNotContains('Setor Alheio', $names);
    }

    public function test_manager_cannot_query_another_event(): void
    {
        $other = $this->otherEventSector->event;

        $this->actingAs($this->manager('A'))->getJson($this->url('tasks', $other))->assertForbidden();
        $this->actingAs($this->manager('A'))->getJson($this->url('occurrences', $other))->assertForbidden();
    }

    public function test_global_admin_can_query_any_event(): void
    {
        $names = array_column($this->actingAs($this->adminUser)->getJson($this->url('occurrences', $this->otherEventSector->event))->assertOk()->json('data'), 'name');

        $this->assertSame(['Setor Alheio'], $names);
    }

    public function test_roles_without_route_permission_get_403(): void
    {
        $denied = [
            $this->editor('A'),
            $this->consultant,
            $this->userWithRole(RoleCode::Juror),
            $this->userWithRole(RoleCode::Voter),
        ];

        foreach ($denied as $user) {
            foreach (['tasks', 'occurrences'] as $kind) {
                $this->actingAs($user)->getJson($this->url($kind))->assertForbidden();
            }
        }
    }

    public function test_guest_gets_401(): void
    {
        $this->getJson($this->url('tasks'))->assertUnauthorized();
    }

    public function test_normal_sector_listing_stays_scoped_for_manager(): void
    {
        $names = array_column($this->actingAs($this->manager('A'))->getJson("/api/v1/events/{$this->event->id}/sectors")->assertOk()->json('data'), 'name');

        $this->assertSame(['Setor A'], $names);
    }

    public function test_manager_forwards_to_a_listed_destination_and_editor_cannot(): void
    {
        $task = Task::factory()->between($this->sectorB, $this->sectorA)->create();
        $destination = collect($this->actingAs($this->manager('A'))->getJson($this->url('tasks'))->json('data'))->firstWhere('name', 'Setor C');

        $this->actingAs($this->editor('A'))
            ->postJson("/api/v1/tasks/{$task->id}/forward", ['sector_id' => $destination['id'], 'reason' => 'Depende do setor C.'])
            ->assertForbidden();

        $this->actingAs($this->manager('A'))
            ->postJson("/api/v1/tasks/{$task->id}/forward", ['sector_id' => $destination['id'], 'reason' => 'Depende do setor C.'])
            ->assertOk()
            ->assertJsonPath('data.responsible_sector.id', $this->sectorC->id);
    }
}
