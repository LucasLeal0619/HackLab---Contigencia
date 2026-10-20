<?php

namespace Tests\Feature\Integrations;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\ReconciliationScenario;
use Tests\DatabaseTestCase;

/**
 * Nome e datas do evento são do HackLab. A sincronização guarda o retrato da Even3 só para avisar a divergência
 * na Configuração Even3; nada é aplicado e não existe endpoint para aplicar.
 */
class EventMetadataReconciliationTest extends DatabaseTestCase
{
    use ReconciliationScenario;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->buildReconciliationScenario();
        $this->event->forceFill(['name' => 'Hackathon Interno', 'start_date' => '2026-11-04', 'end_date' => '2026-11-06'])->save();
    }

    public function test_sync_stores_the_snapshot_and_never_changes_the_event(): void
    {
        $this->fakeEven3();

        $this->actingAs($this->admin())->postJson($this->base().'/sync', ['scope' => 'EVENT'])->assertCreated();

        $integration = $this->integration->fresh();
        $this->assertSame('Hackathon Teste', $integration->external_event_title);
        $this->assertSame('2026-11-04', $integration->external_start_date->toDateString());
        $this->assertSame('Hackathon Interno', $this->event->fresh()->name);
    }

    public function test_differences_are_reported_without_calling_even3(): void
    {
        Http::fake();
        $this->integration->forceFill(['external_event_title' => 'Hackathon Teste', 'external_start_date' => '2026-11-03', 'external_end_date' => '2026-11-06', 'external_metadata_synced_at' => now()])->save();

        $rows = collect($this->actingAs($this->admin())->getJson($this->base().'/event-metadata')->assertOk()->json('data.fields'))->keyBy('field');

        $this->assertTrue($rows['name']['different']);
        $this->assertTrue($rows['start_date']['different']);
        $this->assertFalse($rows['end_date']['different']);
        $this->actingAs($this->admin())->getJson($this->base().'/workbench')->assertJsonPath('data.event_differences', 2);
        Http::assertNothingSent();
    }

    public function test_there_is_no_apply_endpoint(): void
    {
        $this->actingAs($this->admin())->postJson($this->base().'/event-metadata/apply', ['fields' => ['name']])->assertNotFound();
    }
}
