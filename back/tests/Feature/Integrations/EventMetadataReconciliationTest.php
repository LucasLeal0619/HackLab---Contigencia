<?php

namespace Tests\Feature\Integrations;

use App\Domain\Audit\AuditAction;
use App\Domain\Users\Enums\PermissionCode;
use App\Domain\Users\Enums\RoleCode;
use App\Models\AuditLog;
use App\Models\EventDay;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\ReconciliationScenario;
use Tests\DatabaseTestCase;

/**
 * Divergências do evento: o retrato da Even3 é gravado na sincronização; comparar não chama a Even3;
 * aplicar usa só os valores do retrato e passa pelo EventService.
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

    private function snapshot(string $title, ?string $start, ?string $end): void
    {
        $this->integration->forceFill(['external_event_title' => $title, 'external_start_date' => $start, 'external_end_date' => $end, 'external_metadata_synced_at' => now()])->save();
    }

    private function differences(): array
    {
        return collect($this->actingAs($this->admin())->getJson($this->base().'/event-metadata')->assertOk()->json('data.fields'))
            ->keyBy('field')->all();
    }

    public function test_sync_stores_the_external_snapshot(): void
    {
        $this->fakeEven3();

        $this->actingAs($this->admin())->postJson($this->base().'/sync', ['scope' => 'EVENT'])->assertCreated();

        $integration = $this->integration->fresh();
        $this->assertSame('Hackathon Teste', $integration->external_event_title);
        $this->assertSame('2026-11-04', $integration->external_start_date->toDateString());
        $this->assertSame('2026-11-06', $integration->external_end_date->toDateString());
        $this->assertNotNull($integration->external_metadata_synced_at);
    }

    public function test_detects_differences_without_calling_even3(): void
    {
        Http::fake();
        $this->snapshot('Hackathon Teste', '2026-11-03', '2026-11-06');

        $rows = $this->differences();

        $this->assertTrue($rows['name']['different']);
        $this->assertSame('Hackathon Interno', $rows['name']['hacklab']);
        $this->assertSame('Hackathon Teste', $rows['name']['even3']);
        $this->assertTrue($rows['start_date']['different']);
        $this->assertFalse($rows['end_date']['different']);
        $this->actingAs($this->admin())->getJson($this->base().'/workbench')->assertJsonPath('data.pending.event_differences', 2);
        Http::assertNothingSent();
    }

    public function test_no_difference_counts_zero(): void
    {
        $this->snapshot('  hackathon   INTERNO ', '2026-11-04', '2026-11-06');

        $this->actingAs($this->admin())->getJson($this->base().'/workbench')->assertJsonPath('data.pending.event_differences', 0);
    }

    public function test_apply_name_uses_the_backend_snapshot_and_ignores_values_from_the_front(): void
    {
        $this->snapshot('Hackathon Teste', '2026-11-04', '2026-11-06');

        $this->actingAs($this->admin())->postJson($this->base().'/event-metadata/apply', ['fields' => ['name'], 'name' => 'Nome Injetado'])
            ->assertOk()->assertJsonPath('data.name', 'Hackathon Teste');

        $this->assertSame('Hackathon Teste', $this->event->fresh()->name);
        $this->assertTrue(AuditLog::query()->where('action', AuditAction::EVENT_UPDATED)->exists());
        $this->assertTrue(AuditLog::query()->where('action', AuditAction::EVEN3_EVENT_METADATA_APPLIED)->exists());
        $this->actingAs($this->admin())->getJson($this->base().'/workbench')->assertJsonPath('data.pending.event_differences', 0);
    }

    public function test_dates_that_leave_event_days_outside_are_refused(): void
    {
        EventDay::factory()->for($this->event)->create(['date' => '2026-11-04', 'day_number' => 1]);
        $this->snapshot('Hackathon Interno', '2026-11-05', '2026-11-06');

        $this->actingAs($this->admin())->postJson($this->base().'/event-metadata/apply', ['fields' => ['start_date']])
            ->assertUnprocessable()->assertJsonValidationErrors('start_date');
        $this->assertSame('2026-11-04', $this->event->fresh()->start_date->toDateString());
    }

    public function test_unknown_fields_and_missing_snapshot_are_refused(): void
    {
        $this->snapshot('Hackathon Teste', null, null);

        $this->actingAs($this->admin())->postJson($this->base().'/event-metadata/apply', ['fields' => ['status']])->assertUnprocessable()->assertJsonValidationErrors('fields.0');
        $this->actingAs($this->admin())->postJson($this->base().'/event-metadata/apply', ['fields' => ['end_date']])->assertUnprocessable()->assertJsonValidationErrors('fields');
    }

    public function test_requires_events_manage_and_consultant_only_reads(): void
    {
        $this->snapshot('Hackathon Teste', '2026-11-04', '2026-11-06');

        $this->actingAs($this->userWithRole(RoleCode::Consultant))->getJson($this->base().'/event-metadata')->assertOk();
        $this->actingAs($this->userWithRole(RoleCode::Consultant))->postJson($this->base().'/event-metadata/apply', ['fields' => ['name']])->assertForbidden();
        $this->actingAs($this->adminWithout(PermissionCode::EventsManage))->postJson($this->base().'/event-metadata/apply', ['fields' => ['name']])->assertForbidden();
        $this->assertSame('Hackathon Interno', $this->event->fresh()->name);
    }
}
