<?php

namespace Tests\Feature\Integrations;

use App\Domain\Audit\AuditAction;
use App\Domain\Users\Enums\PermissionCode;
use App\Domain\Users\Enums\RoleCode;
use App\Models\AuditLog;
use App\Models\IntegrationReconciliation;
use App\Models\Juror;
use App\Models\User;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\ReconciliationScenario;
use Tests\DatabaseTestCase;

/**
 * Fila de candidatos a Jurado: JUROR externo gera só candidato; o Administrador confirma pelo JurorService.
 * Nunca cria User. Ignorar/restaurar sem mexer em entidade interna.
 */
class ReconciliationJurorTest extends DatabaseTestCase
{
    use ReconciliationScenario;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->buildReconciliationScenario();
    }

    private function queue(string $view = 'pending', ?User $user = null)
    {
        return $this->actingAs($user ?? $this->admin())->getJson($this->base()."/reconciliation/jurors?view={$view}");
    }

    public function test_confirmed_juror_registration_is_one_candidate_per_person(): void
    {
        $person = $this->candidate('ana@teste.local', 'juror', ['full_name' => 'Ana Jurada']);
        $this->inscribe($person, 'juror');

        $items = $this->queue()->assertOk()->assertJsonPath('meta.total', 1)->json('data');

        $this->assertSame('Ana Jurada', $items[0]['person']['full_name']);
        $this->assertSame('ana@teste.local', $items[0]['person']['email']);
        $this->assertSame(['Jurado'], $items[0]['tickets']);
        $this->assertSame('PENDING', $items[0]['state']);
        $this->assertNull($items[0]['internal']['juror']);
    }

    public function test_confirm_creates_juror_through_juror_service_and_never_a_user(): void
    {
        $person = $this->candidate('ana@teste.local', 'juror');
        $admin = $this->admin();

        $this->actingAs($admin)->postJson($this->base()."/reconciliation/jurors/{$person->id}/confirm", ['notes' => 'Banca técnica'])
            ->assertCreated()->assertJsonPath('data.status', 'ACTIVE')->assertJsonPath('data.person.id', $person->id);

        $juror = Juror::query()->where('event_id', $this->event->id)->where('person_id', $person->id)->sole();
        $this->assertSame('Banca técnica', $juror->notes);
        $this->assertSame(0, User::query()->where('person_id', $person->id)->count(), 'jurado não cria conta');

        $decision = IntegrationReconciliation::query()->sole();
        $this->assertSame('RESOLVED', $decision->status->value);
        $this->assertSame('JUROR_CREATED', $decision->resolution);
        $this->assertSame($juror->id, $decision->target_id);
        $this->assertTrue(AuditLog::query()->where('action', AuditAction::JUROR_CREATED)->exists(), 'log de entidade do JurorService');
        $this->assertTrue(AuditLog::query()->where('action', AuditAction::EVEN3_RECONCILIATION_RESOLVED)->exists());

        $this->queue()->assertJsonPath('meta.total', 0);
        $this->queue('all')->assertJsonPath('data.0.state', 'DONE')->assertJsonPath('data.0.internal.juror.status', 'ACTIVE');

        // De novo: já é jurado.
        $this->actingAs($admin)->postJson($this->base()."/reconciliation/jurors/{$person->id}/confirm")->assertUnprocessable();
        $this->assertSame(1, Juror::query()->where('person_id', $person->id)->count());
    }

    public function test_existing_juror_is_not_pending_and_inactive_is_not_reactivated(): void
    {
        $person = $this->candidate('ana@teste.local', 'juror');
        Juror::factory()->for($this->event)->create(['person_id' => $person->id, 'status' => 'INACTIVE']);

        $this->queue()->assertJsonPath('meta.total', 0);
        $this->queue('all')->assertJsonPath('data.0.internal.juror.status', 'INACTIVE');
        $this->actingAs($this->admin())->postJson($this->base()."/reconciliation/jurors/{$person->id}/confirm")->assertUnprocessable();
        $this->assertSame('INACTIVE', Juror::query()->where('person_id', $person->id)->sole()->status->value);
    }

    public function test_untrusted_registrations_are_not_candidates(): void
    {
        $conflict = $this->person('conflito@teste.local');
        $this->inscribe($conflict, 'juror', attributes: ['identity_mismatch' => true]);
        $byName = $this->candidate('nome@teste.local', 'juror_unmapped');

        $this->queue('all')->assertJsonPath('meta.total', 0);
        $this->actingAs($this->admin())->postJson($this->base()."/reconciliation/jurors/{$conflict->id}/confirm")->assertUnprocessable();
        $this->actingAs($this->admin())->postJson($this->base()."/reconciliation/jurors/{$byName->id}/confirm")->assertUnprocessable();
        $this->assertSame(0, Juror::query()->count());
    }

    public function test_ignore_leaves_the_queue_and_restore_brings_it_back(): void
    {
        $person = $this->candidate('ana@teste.local', 'juror');
        $admin = $this->admin();

        $this->actingAs($admin)->postJson($this->base()."/reconciliation/jurors/{$person->id}/ignore", ['note' => 'Não vem mais'])->assertOk();
        $this->queue()->assertJsonPath('meta.total', 0);
        $this->queue('ignored')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.state', 'IGNORED')->assertJsonPath('data.0.decision.note', 'Não vem mais');
        $this->actingAs($admin)->postJson($this->base()."/reconciliation/jurors/{$person->id}/confirm")->assertUnprocessable();

        $this->actingAs($admin)->postJson($this->base()."/reconciliation/jurors/{$person->id}/restore")->assertOk();
        $this->queue()->assertJsonPath('meta.total', 1);
        $this->assertSame(0, IntegrationReconciliation::query()->count());
        $this->assertSame(0, Juror::query()->count(), 'restaurar não mexe em entidade');
        $this->assertTrue(AuditLog::query()->where('action', AuditAction::EVEN3_RECONCILIATION_IGNORED)->exists());
        $this->assertTrue(AuditLog::query()->where('action', AuditAction::EVEN3_RECONCILIATION_RESTORED)->exists());

        $this->actingAs($admin)->postJson($this->base()."/reconciliation/jurors/{$person->id}/restore")->assertUnprocessable();
    }

    public function test_requires_integrations_manage_and_jurors_manage(): void
    {
        $person = $this->candidate('ana@teste.local', 'juror');

        $withoutJurors = $this->adminWithout(PermissionCode::JurorsManage);
        $this->queue('pending', $withoutJurors)->assertForbidden();
        $this->actingAs($withoutJurors)->postJson($this->base()."/reconciliation/jurors/{$person->id}/confirm")->assertForbidden();
        $this->actingAs($withoutJurors)->postJson($this->base()."/reconciliation/jurors/{$person->id}/ignore")->assertForbidden();

        $consultant = $this->userWithRole(RoleCode::Consultant);
        $this->queue('pending', $consultant)->assertForbidden();
        $this->actingAs($consultant)->postJson($this->base()."/reconciliation/jurors/{$person->id}/confirm")->assertForbidden();

        $this->assertSame(0, Juror::query()->count());
    }

    public function test_listing_does_not_audit(): void
    {
        $this->candidate('ana@teste.local', 'juror');
        $logs = AuditLog::query()->count();

        $this->queue()->assertOk();
        $this->queue('all')->assertOk();

        $this->assertSame($logs, AuditLog::query()->count());
    }
}
