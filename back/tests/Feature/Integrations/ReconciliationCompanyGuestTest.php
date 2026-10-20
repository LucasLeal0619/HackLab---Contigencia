<?php

namespace Tests\Feature\Integrations;

use App\Domain\Users\Enums\PermissionCode;
use App\Models\Company;
use App\Models\CompanyRepresentative;
use App\Models\Event;
use App\Models\IntegrationReconciliation;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\ReconciliationScenario;
use Tests\DatabaseTestCase;

/**
 * Fila Empresa/Convidado: nunca cria Company; vincula a uma empresa existente do mesmo evento pelo
 * CompanyRepresentativeService.
 */
class ReconciliationCompanyGuestTest extends DatabaseTestCase
{
    use ReconciliationScenario;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->buildReconciliationScenario();
    }

    public function test_company_guest_is_a_candidate_and_nothing_creates_a_company(): void
    {
        $this->candidate('maria@teste.local', 'company', ['full_name' => 'Maria Souza']);
        $companies = Company::query()->count();

        $this->actingAs($this->admin())->getJson($this->base().'/reconciliation/company-guests')
            ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.person.full_name', 'Maria Souza')
            ->assertJsonPath('data.0.internal.representatives', []);
        $this->actingAs($this->admin())->getJson($this->base().'/workbench')->assertJsonPath('data.pending.company_guests', 1);

        $this->assertSame($companies, Company::query()->count());
    }

    public function test_link_to_existing_company_creates_representative(): void
    {
        $person = $this->candidate('maria@teste.local', 'company');
        $company = Company::factory()->for($this->event)->create();

        $this->actingAs($this->admin())->postJson($this->base()."/reconciliation/company-guests/{$person->id}/link", ['company_id' => $company->id, 'title' => 'Diretora'])
            ->assertCreated()->assertJsonPath('data.person.id', $person->id);

        $representative = CompanyRepresentative::query()->sole();
        $this->assertSame($company->id, $representative->company_id);
        $this->assertSame('Diretora', $representative->title);
        $this->assertTrue($representative->active);
        $this->assertSame('REPRESENTATIVE_LINKED', IntegrationReconciliation::query()->sole()->resolution);
        $this->actingAs($this->admin())->getJson($this->base().'/reconciliation/company-guests')->assertJsonPath('meta.total', 0);
    }

    public function test_company_of_another_event_cannot_be_used(): void
    {
        $person = $this->candidate('maria@teste.local', 'company');
        $foreign = Company::factory()->for(Event::factory())->create();

        $this->actingAs($this->admin())->postJson($this->base()."/reconciliation/company-guests/{$person->id}/link", ['company_id' => $foreign->id])
            ->assertUnprocessable()->assertJsonValidationErrors('company_id');
        $this->assertSame(0, CompanyRepresentative::query()->count());
    }

    public function test_inactive_company_is_refused_by_the_domain_service(): void
    {
        $person = $this->candidate('maria@teste.local', 'company');
        $company = Company::factory()->for($this->event)->create(['status' => 'INACTIVE']);

        $this->actingAs($this->admin())->postJson($this->base()."/reconciliation/company-guests/{$person->id}/link", ['company_id' => $company->id])
            ->assertUnprocessable();
        $this->assertSame(0, CompanyRepresentative::query()->count());
        $this->assertSame(0, IntegrationReconciliation::query()->count(), 'falha não deixa decisão');
    }

    public function test_ignore_and_restore(): void
    {
        $person = $this->candidate('maria@teste.local', 'company');
        $admin = $this->admin();

        $this->actingAs($admin)->postJson($this->base()."/reconciliation/company-guests/{$person->id}/ignore")->assertOk();
        $this->actingAs($admin)->getJson($this->base().'/workbench')->assertJsonPath('data.pending.company_guests', 0)->assertJsonPath('data.ignored.company_guests', 1);
        $this->actingAs($admin)->postJson($this->base()."/reconciliation/company-guests/{$person->id}/restore")->assertOk();
        $this->actingAs($admin)->getJson($this->base().'/workbench')->assertJsonPath('data.pending.company_guests', 1)->assertJsonPath('data.ignored.company_guests', 0);
    }

    public function test_requires_companies_manage(): void
    {
        $person = $this->candidate('maria@teste.local', 'company');
        $company = Company::factory()->for($this->event)->create();
        $user = $this->adminWithout(PermissionCode::CompaniesManage);

        $this->actingAs($user)->getJson($this->base().'/reconciliation/company-guests')->assertForbidden();
        $this->actingAs($user)->postJson($this->base()."/reconciliation/company-guests/{$person->id}/link", ['company_id' => $company->id])->assertForbidden();
        $this->actingAs($user)->postJson($this->base()."/reconciliation/company-guests/{$person->id}/ignore")->assertForbidden();
        $this->assertSame(0, CompanyRepresentative::query()->count());
    }
}
