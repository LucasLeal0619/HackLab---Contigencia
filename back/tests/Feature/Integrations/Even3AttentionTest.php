<?php

namespace Tests\Feature\Integrations;

use App\Domain\Integrations\Enums\RegistrationStatus;
use App\Domain\Integrations\Enums\SyncScope;
use App\Domain\Integrations\Enums\SyncStatus;
use App\Domain\Integrations\Enums\SyncTrigger;
use App\Domain\Users\Enums\PermissionCode;
use App\Domain\Users\Enums\RoleCode;
use App\Models\AttendanceRecord;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\CompanyRepresentative;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\IntegrationSyncRun;
use App\Models\Participant;
use App\Models\Sector;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\ReconciliationScenario;
use Tests\DatabaseTestCase;

/**
 * Tela Presença (simplificação pré-deploy): resumo, lista única "Precisa de atenção" (só exceções reais),
 * vínculo manual de representante e lista de inscritos com presença. Tudo do PostgreSQL, sem chamar a Even3.
 */
class Even3AttentionTest extends DatabaseTestCase
{
    use ReconciliationScenario;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->buildReconciliationScenario();
    }

    private function summary(?User $user = null): array
    {
        return $this->actingAs($user ?? $this->admin())->getJson($this->base().'/workbench')->assertOk()->json('data');
    }

    private function attention(?User $user = null): array
    {
        return $this->actingAs($user ?? $this->admin())->getJson($this->base().'/attention')->assertOk()->json('data');
    }

    private function types(): array
    {
        return collect($this->attention())->map(fn ($item) => [$item['type'], $item['person']['email'] ?? $item['ticket']['name']])->sort()->values()->all();
    }

    public function test_existing_participant_is_validated_and_missing_one_needs_attention(): void
    {
        $known = $this->candidate('ana@teste.local', 'participant');
        Participant::factory()->for($this->event)->create(['person_id' => $known->id]);
        $missing = $this->candidate('bia@teste.local', 'participant');
        $this->inscribe($missing, 'participant'); // duas inscrições: uma pendência só

        $this->assertSame([['PARTICIPANT_NOT_FOUND', 'bia@teste.local']], $this->types());
        $this->assertSame(1, Participant::query()->count(), 'nada é criado');
    }

    public function test_participant_of_another_event_does_not_validate(): void
    {
        $person = $this->candidate('ana@teste.local', 'participant');
        Participant::factory()->for(Event::factory())->create(['person_id' => $person->id]);

        $this->assertContains(['PARTICIPANT_NOT_FOUND', 'ana@teste.local'], $this->types());
    }

    public function test_identity_mismatch_is_a_conflict_not_a_missing_participant(): void
    {
        $this->inscribe($this->person('conflito@teste.local'), 'participant', attributes: ['identity_mismatch' => true]);
        $this->inscribe($this->person('pendente@teste.local'), 'participant', RegistrationStatus::Pending);

        $types = $this->types();
        $this->assertContains(['IDENTITY_CONFLICT', 'conflito@teste.local'], $types);
        $this->assertNotContains(['PARTICIPANT_NOT_FOUND', 'conflito@teste.local'], $types);
        $this->assertNotContains(['PARTICIPANT_NOT_FOUND', 'pendente@teste.local'], $types, 'pendente não é exceção');
    }

    public function test_new_registrations_of_safe_categories_are_not_pending(): void
    {
        $this->candidate('publico@teste.local', 'public');
        $this->candidate('org@teste.local', 'organization');
        $this->candidate('jurado@teste.local', 'juror');

        $this->assertSame([], $this->types(), 'inscrição nova não é pendência');
    }

    public function test_unmapped_ticket_with_registrations_needs_attention(): void
    {
        $this->candidate('alguem@teste.local', 'unmapped');

        $this->assertSame([['UNMAPPED_TICKET', 'Público']], $this->types());
    }

    public function test_company_guest_without_company_is_pending_and_linking_clears_it(): void
    {
        $person = $this->candidate('maria@teste.local', 'company', ['full_name' => 'Maria Silva']);
        $company = Company::factory()->for($this->event)->create(['name' => 'Senac Tech']);
        $companies = Company::query()->count();

        $this->assertContains(['COMPANY_UNLINKED', 'maria@teste.local'], $this->types());

        $this->actingAs($this->admin())->postJson($this->base()."/company-guests/{$person->id}/link", ['company_id' => $company->id])
            ->assertCreated()->assertJsonPath('data.person.id', $person->id);

        $this->assertSame(1, CompanyRepresentative::query()->where('company_id', $company->id)->where('person_id', $person->id)->count());
        $this->assertNotContains(['COMPANY_UNLINKED', 'maria@teste.local'], $this->types());
        $this->assertSame($companies, Company::query()->count(), 'nunca cria empresa');
        // De novo: não é mais pendência.
        $this->actingAs($this->admin())->postJson($this->base()."/company-guests/{$person->id}/link", ['company_id' => $company->id])->assertUnprocessable();
    }

    public function test_company_guest_already_representing_is_not_pending_and_other_event_company_is_refused(): void
    {
        $linked = $this->candidate('ok@teste.local', 'company');
        CompanyRepresentative::query()->create(['company_id' => Company::factory()->for($this->event)->create()->id, 'person_id' => $linked->id, 'is_primary' => false, 'active' => true]);
        $person = $this->candidate('maria@teste.local', 'company');
        $foreign = Company::factory()->for(Event::factory())->create();

        $this->assertNotContains(['COMPANY_UNLINKED', 'ok@teste.local'], $this->types());
        $this->actingAs($this->admin())->postJson($this->base()."/company-guests/{$person->id}/link", ['company_id' => $foreign->id])
            ->assertUnprocessable()->assertJsonValidationErrors('company_id');
    }

    public function test_link_requires_companies_manage(): void
    {
        $person = $this->candidate('maria@teste.local', 'company');
        $company = Company::factory()->for($this->event)->create();

        $this->actingAs($this->adminWithout(PermissionCode::CompaniesManage))->postJson($this->base()."/company-guests/{$person->id}/link", ['company_id' => $company->id])->assertForbidden();
        $this->actingAs($this->userWithRole(RoleCode::Consultant))->postJson($this->base()."/company-guests/{$person->id}/link", ['company_id' => $company->id])->assertForbidden();
        $this->assertSame(0, CompanyRepresentative::query()->count());
    }

    public function test_summary_counts_people_presence_and_attention_without_calling_even3(): void
    {
        Http::fake();
        $present = $this->candidate('presente@teste.local', 'participant');
        Participant::factory()->for($this->event)->create(['person_id' => $present->id]);
        $registration = $this->inscribe($present, 'participant');
        AttendanceRecord::query()->create(['event_id' => $this->event->id, 'event_integration_id' => $this->integration->id, 'event_registration_id' => $registration->id,
            'kind' => 'EVENT', 'observed_at' => now(), 'source' => 'WEBHOOK']);
        $this->candidate('semregistro@teste.local', 'public');
        $this->candidate('naolocalizado@teste.local', 'participant');
        $this->candidate('semmapa@teste.local', 'unmapped');
        $run = new IntegrationSyncRun(['event_integration_id' => $this->integration->id, 'scope' => SyncScope::All, 'trigger' => SyncTrigger::Manual, 'started_at' => now()]);
        $run->forceFill(['status' => SyncStatus::Running, 'counters' => []])->save();

        $data = $this->summary();

        $this->assertTrue($data['configured']);
        $this->assertTrue($data['sync']['running']);
        $this->assertSame(['registered' => 4, 'present' => 1, 'without_record' => 3, 'public_without_access' => 1], $data['totals']);
        $this->assertSame(2, $data['attention']['total']); // participante não localizado + entrada "Público" sem mapeamento
        $this->assertSame(1, $data['attention']['PARTICIPANT_NOT_FOUND']);
        $this->assertSame(['active' => 7, 'mapped' => 5], $data['tickets']); // cenário: 7 entradas, 2 sem mapeamento
        Http::assertNothingSent();
    }

    public function test_registrations_list_filters_by_presence(): void
    {
        $present = $this->candidate('presente@teste.local', 'participant');
        $registration = EventRegistration::query()->where('person_id', $present->id)->sole();
        AttendanceRecord::query()->create(['event_id' => $this->event->id, 'event_integration_id' => $this->integration->id, 'event_registration_id' => $registration->id,
            'kind' => 'EVENT', 'observed_at' => now(), 'source' => 'WEBHOOK']);
        $this->candidate('semregistro@teste.local', 'public');
        $url = "/api/v1/events/{$this->event->id}/event-registrations";

        $this->actingAs($this->admin())->getJson("{$url}?presence=present")->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.check_ins', 1);
        $this->actingAs($this->admin())->getJson("{$url}?presence=none")->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.check_ins', 0);
    }

    public function test_permissions_and_privacy(): void
    {
        $this->candidate('sigilo@teste.local', 'participant', ['full_name' => 'Pessoa Sigilosa']);
        $logs = AuditLog::query()->count();

        $workbench = $this->actingAs($this->admin())->getJson($this->base().'/workbench')->assertOk()->getContent();
        $this->assertStringNotContainsString('sigilo@teste.local', $workbench);
        $this->assertStringNotContainsString(self::EVEN3_TOKEN, $workbench);
        $this->assertStringNotContainsString(self::EVEN3_WEBHOOK_SECRET, $workbench);
        $this->assertSame(1, $this->summary($this->userWithRole(RoleCode::Consultant))['attention']['PARTICIPANT_NOT_FOUND']);
        $this->attention($this->userWithRole(RoleCode::Consultant));

        $sector = Sector::factory()->for($this->event)->create();
        foreach ([$this->sectorUser(RoleCode::Manager, $sector), $this->sectorUser(RoleCode::Editor, $sector), $this->userWithRole(RoleCode::Juror), $this->userWithRole(RoleCode::Voter)] as $user) {
            $this->actingAs($user)->getJson($this->base().'/workbench')->assertForbidden();
            $this->actingAs($user)->getJson($this->base().'/attention')->assertForbidden();
        }
        $this->assertSame($logs, AuditLog::query()->count(), 'consultas não auditam');
    }

    public function test_registration_never_exposes_the_full_document(): void
    {
        $registration = $this->inscribe($this->person('doc@teste.local'), 'participant', attributes: ['source_document' => '12345678909']);

        $this->actingAs($this->admin())->getJson("/api/v1/event-registrations/{$registration->id}")
            ->assertOk()->assertJsonPath('data.source.document', '•••909');
        $this->actingAs($this->admin())->getJson("/api/v1/events/{$this->event->id}/event-registrations")
            ->assertOk()->assertDontSee('12345678909');
    }

    public function test_event_without_integration(): void
    {
        $other = Event::factory()->create();

        $this->actingAs($this->admin())->getJson("/api/v1/events/{$other->id}/integrations/even3/workbench")
            ->assertOk()->assertJsonPath('data.configured', false)->assertJsonPath('data.attention', null);
        $this->actingAs($this->admin())->getJson("/api/v1/events/{$other->id}/integrations/even3/attention")
            ->assertOk()->assertJsonPath('meta.total', 0);
    }
}
