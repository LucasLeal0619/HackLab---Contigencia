<?php

namespace Tests\Feature\Finance;

use App\Domain\Audit\AuditAction;
use App\Domain\Users\Enums\RoleCode;
use App\Models\Event;
use App\Models\Sector;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\DatabaseTestCase;

class FinancialItemTest extends DatabaseTestCase
{
    private Event $event;

    private Sector $financeSector;

    protected function setUp(): void
    {
        parent::setUp();

        $this->event = Event::factory()->create();
        $this->financeSector = Sector::factory()->for($this->event)->create(['name' => 'Setor Financeiro']);
    }

    public function test_admin_creates_and_updates_financial_items_with_calculated_totals_and_audit(): void
    {
        $admin = $this->admin();
        $url = "/api/v1/events/{$this->event->id}/financial-items";

        $id = $this->actingAs($admin)->postJson($url, $this->itemData([
            'quantity' => '10',
            'unit_price' => '5',
            'status' => 'Cotado',
        ]))
            ->assertCreated()
            ->assertJsonPath('data.total_amount', '50.00')
            ->json('data.id');

        $this->actingAs($admin)
            ->patchJson("/api/v1/financial-items/{$id}", ['quantity' => '4.5'])
            ->assertOk()
            ->assertJsonPath('data.total_amount', '22.50');

        $this->assertDatabaseHas('audit_logs', ['action' => AuditAction::FINANCIAL_ITEM_CREATED, 'entity_id' => (string) $id]);
        $this->assertDatabaseHas('audit_logs', ['action' => AuditAction::FINANCIAL_ITEM_UPDATED, 'entity_id' => (string) $id]);
    }

    public function test_pending_price_is_null_and_zero_price_is_a_real_amount(): void
    {
        $admin = $this->admin();
        $url = "/api/v1/events/{$this->event->id}/financial-items";

        $this->actingAs($admin)->postJson($url, $this->itemData(['unit_price' => null, 'status' => 'A cotar']))
            ->assertCreated()
            ->assertJsonPath('data.unit_price', null)
            ->assertJsonPath('data.total_amount', null);

        $this->actingAs($admin)->postJson($url, $this->itemData(['unit_price' => '0', 'status' => 'Brinde']))
            ->assertCreated()
            ->assertJsonPath('data.unit_price', '0.0000')
            ->assertJsonPath('data.total_amount', '0.00');
    }

    public function test_finance_access_is_global_for_admin_and_scoped_to_active_finance_sector_for_manager_and_editor(): void
    {
        $manager = $this->sectorUser(RoleCode::Manager, $this->financeSector);
        $editor = $this->sectorUser(RoleCode::Editor, $this->financeSector);
        $otherSector = Sector::factory()->for($this->event)->create(['name' => 'Produção']);
        $otherManager = $this->sectorUser(RoleCode::Manager, $otherSector);
        $consultant = $this->userWithRole(RoleCode::Consultant);
        $url = "/api/v1/events/{$this->event->id}/financial-items";

        $this->actingAs($manager)->getJson($url)->assertOk();
        $this->actingAs($editor)->postJson($url, $this->itemData())->assertCreated();
        $this->actingAs($otherManager)->getJson($url)->assertForbidden();
        $this->actingAs($consultant)->getJson($url)->assertForbidden();

        $otherEvent = Event::factory()->create();
        $this->actingAs($manager)->getJson("/api/v1/events/{$otherEvent->id}/financial-items")->assertForbidden();

        $this->financeSector->update(['active' => false]);
        $manager->unsetRelation('sector');
        $this->actingAs($manager)->getJson($url)->assertForbidden();
        $this->actingAs($this->admin())->getJson($url)->assertOk();
    }

    public function test_negative_values_and_client_supplied_totals_are_rejected(): void
    {
        $admin = $this->admin();
        $url = "/api/v1/events/{$this->event->id}/financial-items";

        $this->actingAs($admin)->postJson($url, $this->itemData(['quantity' => '-1']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['quantity']);
        $this->actingAs($admin)->postJson($url, $this->itemData(['unit_price' => '-0.01']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['unit_price']);
        $this->actingAs($admin)->postJson($url, $this->itemData(['total_amount' => '999']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['total_amount']);
    }

    public function test_database_rejects_negative_quantity_and_unit_price(): void
    {
        $base = [
            'event_id' => $this->event->id,
            'category' => 'Estrutural',
            'item' => 'Microfone',
            'specification' => 'Sem fio',
            'quantity' => '1',
            'unit' => 'un',
            'unit_price' => null,
            'status' => 'A cotar',
            'created_at' => now(),
            'updated_at' => now(),
        ];

        $this->assertDatabaseRefuses(fn () => DB::table('financial_items')->insert([...$base, 'quantity' => '-1']));
        $this->assertDatabaseRefuses(fn () => DB::table('financial_items')->insert([...$base, 'item' => 'Caixa', 'unit_price' => '-1']));
    }

    public function test_delete_removes_the_item_and_is_audited(): void
    {
        $admin = $this->admin();
        $id = $this->actingAs($admin)
            ->postJson("/api/v1/events/{$this->event->id}/financial-items", $this->itemData())
            ->assertCreated()
            ->json('data.id');

        $this->actingAs($admin)->deleteJson("/api/v1/financial-items/{$id}")->assertNoContent();

        $this->assertDatabaseMissing('financial_items', ['id' => $id]);
        $this->assertDatabaseHas('audit_logs', ['action' => AuditAction::FINANCIAL_ITEM_DELETED, 'entity_id' => (string) $id]);
    }

    /**
     * @return array<string, mixed>
     */
    private function itemData(array $overrides = []): array
    {
        return array_replace([
            'category' => 'Coffee Break - Abertura',
            'item' => 'Copo',
            'specification' => 'Descartável',
            'quantity' => '10',
            'unit' => 'un',
            'unit_price' => '2.5',
            'supplier' => null,
            'status' => 'Estimado',
            'notes' => null,
        ], $overrides);
    }

    private function assertDatabaseRefuses(callable $statement): void
    {
        try {
            DB::transaction(fn () => $statement());
        } catch (QueryException) {
            $this->addToAssertionCount(1);

            return;
        }

        $this->fail('O banco aceitou um valor financeiro negativo.');
    }
}
