<?php

namespace Tests\Feature\Integrations;

use App\Domain\Audit\AuditAction;
use App\Domain\Integrations\Enums\RegistrationStatus;
use App\Domain\Integrations\Enums\SyncScope;
use App\Domain\Integrations\Enums\SyncStatus;
use App\Domain\Integrations\Enums\SyncTrigger;
use App\Domain\Integrations\Enums\TicketCategory;
use App\Domain\Integrations\IntegrationSyncService;
use App\Domain\Integrations\RegistrationRecorder;
use App\Domain\Users\Enums\RoleCode;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\CompanyRepresentative;
use App\Models\Event;
use App\Models\EventIntegration;
use App\Models\EventRegistration;
use App\Models\IntegrationSyncRun;
use App\Models\Juror;
use App\Models\Participant;
use App\Models\Person;
use App\Models\User;
use App\Models\VotingEligibility;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\Even3Fakes;
use Tests\DatabaseTestCase;

/**
 * Participantes Even3 → Person + EventRegistration: matching controlado, status, categorias por entrada
 * conhecida, idempotência, falhas parciais e um sync por integração. Nada de conta/perfil/domínio automático.
 */
class Even3AttendeeSyncTest extends DatabaseTestCase
{
    use Even3Fakes;

    private Event $event;

    private EventIntegration $integration;

    private User $adminUser;

    private int $basePersons;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureEven3();
        $this->event = Event::factory()->create(['timezone' => 'America/Sao_Paulo']);
        $this->integration = $this->even3Integration($this->event);
        $this->ticketType($this->integration, '10', 'Participante', TicketCategory::Participant);
        $this->ticketType($this->integration, '20', 'Público', TicketCategory::Public);
        $this->ticketType($this->integration, '30', 'Jurado', TicketCategory::Juror);
        $this->ticketType($this->integration, '40', 'Empresa / Convidado', TicketCategory::CompanyGuest);
        $this->adminUser = $this->admin();
        $this->basePersons = Person::query()->count(); // o Administrador também é uma Person
    }

    /**
     * @param  list<array<string, mixed>>  $attendees
     * @return array<string, mixed>
     */
    private function syncAttendees(array $attendees): array
    {
        $this->refakeHttp([self::EVEN3_BASE.'/attendees*' => Http::response($this->page($attendees))]);

        return $this->actingAs($this->adminUser)->postJson("/api/v1/events/{$this->event->id}/integrations/even3/sync", ['scope' => 'ATTENDEES'])
            ->assertCreated()->json('data');
    }

    public function test_new_attendee_creates_person_and_registration_and_repeat_is_idempotent(): void
    {
        $attendee = $this->externalAttendee(1, ['name' => 'Ana Lima', 'email' => '  Ana@Teste.Even3 ', 'document_number' => '123.456.789-09']);

        $run = $this->syncAttendees([$attendee]);
        $this->assertSame('SUCCESS', $run['status']);
        $this->assertSame(1, $run['counters']['attendees_created']);
        $this->assertSame(1, $run['counters']['persons_created']);

        $registration = EventRegistration::query()->sole();
        $this->assertSame('1', $registration->external_attendee_id);
        $this->assertSame(RegistrationStatus::Confirmed, $registration->status);
        $this->assertSame('10', $registration->ticketType->external_ticket_id);
        $this->assertSame('5001', $registration->check_in_code);
        $this->assertSame('ana@teste.even3', $registration->source_email);
        $this->assertSame('12345678909', $registration->source_document);
        $this->assertSame('2026-10-22T16:10:00+00:00', $registration->registered_at->toIso8601String()); // 13:10 em São Paulo
        $this->assertSame(['Ana Lima', 'ana@teste.even3', '12345678909'], [$registration->person->full_name, $registration->person->email, $registration->person->document]);

        // sync, sync, sync sem mudança externa: nada novo, nada "atualizado".
        foreach (range(1, 3) as $i) {
            $run = $this->syncAttendees([$attendee]);
            $this->assertSame(['attendees_created' => 0, 'attendees_updated' => 0, 'attendees_unchanged' => 1, 'persons_created' => 0], array_intersect_key($run['counters'], array_flip(['attendees_created', 'attendees_updated', 'attendees_unchanged', 'persons_created'])));
        }

        $this->assertSame($this->basePersons + 1, Person::query()->count());
        $this->assertSame(1, EventRegistration::query()->count());
    }

    public function test_matches_existing_person_by_document_then_by_email_without_overwriting(): void
    {
        $byDoc = Person::factory()->create(['full_name' => 'Nome Interno', 'email' => 'interno@hacklab.test', 'document' => '123.456.789-09', 'phone' => null]);
        $byEmail = Person::factory()->create(['full_name' => 'Outra Pessoa', 'email' => 'beto@teste.even3', 'document' => null]);

        $this->syncAttendees([
            $this->externalAttendee(1, ['name' => 'Nome Na Even3', 'email' => 'outro@teste.even3', 'document_number' => '12345678909']),
            $this->externalAttendee(2, ['name' => 'Beto', 'email' => 'BETO@teste.even3', 'document_number' => '98765432100']),
        ]);

        $first = EventRegistration::query()->where('external_attendee_id', '1')->sole();
        $second = EventRegistration::query()->where('external_attendee_id', '2')->sole();
        $this->assertSame($byDoc->id, $first->person_id);
        $this->assertSame($byEmail->id, $second->person_id);
        $this->assertSame($this->basePersons + 2, Person::query()->count());

        // Cadastro interno não é sobrescrito; campos vazios são preenchidos; origem fica em source_*.
        $byDoc->refresh();
        $this->assertSame(['Nome Interno', 'interno@hacklab.test'], [$byDoc->full_name, $byDoc->email]);
        $this->assertSame(['Nome Na Even3', 'outro@teste.even3'], [$first->source_name, $first->source_email]);
        $this->assertTrue($first->identity_mismatch, 'e-mail da origem difere do interno');
        $this->assertSame('98765432100', $byEmail->fresh()->document, 'documento vazio foi preenchido');
        $this->assertFalse($second->identity_mismatch);
    }

    public function test_document_and_email_pointing_to_different_people_is_a_conflict(): void
    {
        Person::factory()->create(['email' => 'a@teste.even3', 'document' => null]);
        Person::factory()->create(['email' => null, 'document' => '11111111111']);

        $run = $this->syncAttendees([
            $this->externalAttendee(1, ['email' => 'a@teste.even3', 'document_number' => '111.111.111-11']),
            $this->externalAttendee(2),
        ]);

        $this->assertSame('PARTIAL', $run['status']);
        $this->assertSame(1, $run['counters']['attendees_conflicts']);
        $this->assertSame(['IDENTITY_CONFLICT' => 1], $run['warning_codes']);
        $this->assertSame($this->basePersons + 3, Person::query()->count(), 'nenhuma terceira pessoa para o conflito (só a do participante 2)');
        $this->assertNull(EventRegistration::query()->where('external_attendee_id', '1')->first());
    }

    public function test_same_person_may_have_several_external_registrations(): void
    {
        // Mesma pessoa (mesmo e-mail), dois participantes distintos na Even3: duas inscrições, uma Person.
        $this->syncAttendees([
            $this->externalAttendee(1, ['email' => 'dupla@teste.even3', 'document_number' => '', 'registration_category' => 'Participante']),
            $this->externalAttendee(2, ['email' => 'dupla@teste.even3', 'document_number' => '', 'registration_category' => 'Público']),
        ]);

        $this->assertSame($this->basePersons + 1, Person::query()->count());
        $this->assertSame(2, EventRegistration::query()->distinct()->count('external_attendee_id'));
        $this->assertSame(1, EventRegistration::query()->distinct()->count('person_id'));

        // Repetir não duplica nem mistura (cada uma casa pelo próprio external_attendee_id).
        $run = $this->syncAttendees([
            $this->externalAttendee(1, ['email' => 'dupla@teste.even3', 'document_number' => '', 'registration_category' => 'Participante']),
            $this->externalAttendee(2, ['email' => 'dupla@teste.even3', 'document_number' => '', 'registration_category' => 'Público']),
        ]);
        $this->assertSame(2, $run['counters']['attendees_unchanged']);
        $this->assertSame(2, EventRegistration::query()->count());
    }

    public function test_ambiguous_candidate_registrations_are_a_conflict_not_a_merge(): void
    {
        // Duas inscrições da mesma pessoa vindas do webhook (ids de inscrição diferentes, sem id de participante).
        $person = Person::factory()->create(['email' => 'ambigua@teste.even3', 'document' => null]);
        foreach (['500', '501'] as $registrationId) {
            $registration = new EventRegistration([
                'event_id' => $this->event->id, 'event_integration_id' => $this->integration->id, 'person_id' => $person->id,
                'external_registration_id' => $registrationId, 'source_name' => 'Ambígua',
            ]);
            $registration->forceFill(['status' => RegistrationStatus::Confirmed])->save();
        }

        // Participante da API só com e-mail: duas candidatas compatíveis → conflito, nada é mesclado.
        $run = $this->syncAttendees([$this->externalAttendee(9, ['email' => 'ambigua@teste.even3', 'document_number' => ''])]);

        $this->assertSame('PARTIAL', $run['status']);
        $this->assertSame(['REGISTRATION_AMBIGUOUS' => 1], $run['warning_codes']);
        $this->assertSame(0, EventRegistration::query()->whereNotNull('external_attendee_id')->count());
        $this->assertSame(2, EventRegistration::query()->count());
    }

    public function test_different_check_in_code_is_another_registration_of_the_same_person(): void
    {
        $person = Person::factory()->create(['email' => 'codigo@teste.even3', 'document' => null]);
        $existing = new EventRegistration([
            'event_id' => $this->event->id, 'event_integration_id' => $this->integration->id, 'person_id' => $person->id,
            'external_registration_id' => '700', 'check_in_code' => '111', 'source_name' => 'Código',
        ]);
        $existing->forceFill(['status' => RegistrationStatus::Confirmed])->save();

        $this->syncAttendees([$this->externalAttendee(9, ['email' => 'codigo@teste.even3', 'document_number' => '', 'check_in_code' => 222])]);

        $this->assertSame(2, EventRegistration::query()->where('person_id', $person->id)->count());
        $this->assertNull($existing->fresh()->external_attendee_id, 'a inscrição existente não foi mesclada');
    }

    public function test_external_ids_take_priority_and_conflicting_ids_are_reported(): void
    {
        $this->syncAttendees([$this->externalAttendee(1, ['email' => 'um@teste.even3']), $this->externalAttendee(2, ['email' => 'dois@teste.even3'])]);
        $one = EventRegistration::query()->where('external_attendee_id', '1')->sole();
        $one->forceFill(['external_registration_id' => 'R-1'])->save();

        // Webhook com id de inscrição R-1 e e-mail de outra pessoa: casa pelo id (prioridade), não pelo e-mail.
        $outcome = app(RegistrationRecorder::class)->record($this->integration, [
            'external_event_id' => self::EXTERNAL_EVENT_ID, 'external_registration_id' => 'R-1', 'external_attendee_id' => null, 'external_person_id' => null,
            'name' => 'Outro Nome', 'email' => 'dois@teste.even3', 'document' => null, 'category_label' => null,
        ], 'WEBHOOK', RegistrationStatus::Cancelled);
        $this->assertSame($one->id, $outcome['registration']->id);
        $this->assertSame(RegistrationStatus::Cancelled, $one->fresh()->status);

        // IDs que apontam para inscrições diferentes: conflito.
        $outcome = app(RegistrationRecorder::class)->record($this->integration, [
            'external_event_id' => self::EXTERNAL_EVENT_ID, 'external_registration_id' => 'R-1', 'external_attendee_id' => '2', 'external_person_id' => null,
            'name' => 'X', 'email' => null, 'document' => null, 'category_label' => null,
        ], 'WEBHOOK');
        $this->assertSame('conflict', $outcome['result']);
        $this->assertSame('REGISTRATION_ID_CONFLICT', $outcome['warnings'][0]['code']);
    }

    public function test_registration_date_outside_the_documented_format_is_not_interpreted(): void
    {
        // Observado no PoC real: ISO com dia/mês aparentemente invertidos. Não é confiado.
        $this->syncAttendees([$this->externalAttendee(1, ['registration_date' => '2026-12-10T00:00:00Z', 'registration_hour' => '12:56'])]);

        $this->assertNull(EventRegistration::query()->sole()->registered_at);
    }

    public function test_status_rules_and_explicit_cancellation_is_preserved(): void
    {
        $this->syncAttendees([$this->externalAttendee(1, ['is_confirmed' => false])]);
        $registration = EventRegistration::query()->sole();
        $this->assertSame(RegistrationStatus::Pending, $registration->status);

        $this->syncAttendees([$this->externalAttendee(1, ['is_confirmed' => true])]);
        $this->assertSame(RegistrationStatus::Confirmed, $registration->fresh()->status);

        // Cancelamento explícito (webhook) não é desfeito por is_confirmed=false da API.
        $registration->forceFill(['status' => RegistrationStatus::Cancelled, 'status_changed_at' => now()])->save();
        $this->syncAttendees([$this->externalAttendee(1, ['is_confirmed' => false])]);
        $this->assertSame(RegistrationStatus::Cancelled, $registration->fresh()->status);
    }

    public function test_absence_in_a_sync_never_cancels(): void
    {
        $this->syncAttendees([$this->externalAttendee(1), $this->externalAttendee(2)]);
        $this->syncAttendees([$this->externalAttendee(1)]);

        $this->assertSame([RegistrationStatus::Confirmed, RegistrationStatus::Confirmed], EventRegistration::query()->orderBy('id')->get()->pluck('status')->all());
    }

    public function test_attendee_of_another_external_event_is_rejected(): void
    {
        $run = $this->syncAttendees([$this->externalAttendee(1, ['event_id' => 999]), $this->externalAttendee(2)]);

        $this->assertSame('PARTIAL', $run['status']);
        $this->assertSame(1, $run['counters']['attendees_rejected']);
        $this->assertSame(['EVENT_MISMATCH' => 1], $run['warning_codes']);
        $this->assertSame(['2'], EventRegistration::query()->pluck('external_attendee_id')->all());
    }

    public function test_category_text_maps_only_to_a_single_known_ticket(): void
    {
        $this->ticketType($this->integration, '50', 'Duplicada');
        $this->ticketType($this->integration, '51', ' duplicada ');

        $run = $this->syncAttendees([
            $this->externalAttendee(1, ['registration_category' => '  PARTICIPANTE ']),
            $this->externalAttendee(2, ['registration_category' => 'Duplicada']),
            $this->externalAttendee(3, ['registration_category' => 'Categoria Nova']),
            $this->externalAttendee(4, ['registration_category' => '']),
        ]);

        $tickets = EventRegistration::query()->orderBy('external_attendee_id')->with('ticketType')->get()->map(fn ($r) => $r->ticketType?->external_ticket_id)->all();
        $this->assertSame(['10', null, null, null], $tickets);
        $this->assertSame(['CATEGORY_AMBIGUOUS' => 1], $run['warning_codes']);
        $this->assertSame('SUCCESS', $run['status'], 'categoria ambígua é aviso, não falha');
        $this->assertSame('Categoria Nova', EventRegistration::query()->where('external_attendee_id', '3')->sole()->external_category_label);
        $this->assertSame(4, EventRegistration::query()->count(), 'inscrição preservada mesmo sem entrada');
    }

    public function test_external_categories_never_grant_accounts_roles_or_domain_links(): void
    {
        Company::factory()->for($this->event)->create(['name' => 'Empresa X']);
        $before = [User::query()->count(), Juror::query()->count(), Participant::query()->count(), Company::query()->count(), CompanyRepresentative::query()->count(), VotingEligibility::query()->count()];

        $this->syncAttendees([
            $this->externalAttendee(1, ['registration_category' => 'Jurado']),
            $this->externalAttendee(2, ['registration_category' => 'Público']),
            $this->externalAttendee(3, ['registration_category' => 'Empresa / Convidado']),
            $this->externalAttendee(4, ['registration_category' => 'Participante']),
        ]);

        $this->assertSame(4, EventRegistration::query()->count());
        $this->assertSame($before, [User::query()->count(), Juror::query()->count(), Participant::query()->count(), Company::query()->count(), CompanyRepresentative::query()->count(), VotingEligibility::query()->count()]);
    }

    public function test_external_read_failure_marks_failed_without_leaking_the_token(): void
    {
        $this->refakeHttp([self::EVEN3_BASE.'/attendees*' => Http::response(['detail' => 'Bearer '.self::EVEN3_TOKEN], 401)]);

        $response = $this->actingAs($this->adminUser)->postJson("/api/v1/events/{$this->event->id}/integrations/even3/sync", ['scope' => 'ATTENDEES'])->assertCreated();

        $this->assertSame('FAILED', $response->json('data.status'));
        $this->assertSame('UNAUTHORIZED', $response->json('data.error_code'));
        $this->assertStringNotContainsString(self::EVEN3_TOKEN, $response->getContent());
        $this->assertStringNotContainsString(self::EVEN3_TOKEN, json_encode(IntegrationSyncRun::query()->sole()->getAttributes()));
        $this->assertSame('FAILED', $this->integration->fresh()->last_sync_status->value);
    }

    public function test_paginated_attendees_are_all_processed(): void
    {
        $this->refakeHttp([
            self::EVEN3_BASE.'/attendees?page=1&page_size=100' => Http::response($this->page([$this->externalAttendee(1), $this->externalAttendee(2)], 1, 2)),
            self::EVEN3_BASE.'/attendees?page=2&page_size=100' => Http::response($this->page([$this->externalAttendee(3)], 2, 2)),
        ]);

        $run = $this->actingAs($this->adminUser)->postJson("/api/v1/events/{$this->event->id}/integrations/even3/sync", ['scope' => 'ATTENDEES'])->json('data');

        $this->assertSame(2, $run['counters']['attendee_pages']);
        $this->assertSame(3, $run['counters']['attendees_created']);
    }

    public function test_one_running_sync_per_integration(): void
    {
        $running = app(IntegrationSyncService::class)->start($this->integration, SyncScope::All, SyncTrigger::Manual, $this->adminUser);

        $this->actingAs($this->adminUser)->postJson("/api/v1/events/{$this->event->id}/integrations/even3/sync", ['scope' => 'ALL'])->assertStatus(409);

        // Banco: índice parcial impede dois RUNNING.
        $this->expectException(QueryException::class);
        IntegrationSyncRun::query()->create(['event_integration_id' => $this->integration->id, 'scope' => 'ALL', 'trigger' => 'MANUAL', 'started_at' => now()])
            ->forceFill(['status' => SyncStatus::Running])->save();
        unset($running);
    }

    public function test_abandoned_running_sync_is_closed_as_stale(): void
    {
        $stale = app(IntegrationSyncService::class)->start($this->integration, SyncScope::All, SyncTrigger::Manual, $this->adminUser);
        $stale->forceFill(['started_at' => now()->subHour()])->save();

        $this->syncAttendees([$this->externalAttendee(1)]);

        $this->assertSame('FAILED', $stale->fresh()->status->value);
        $this->assertSame('STALE_RUN', $stale->fresh()->error_code);
    }

    public function test_manual_sync_is_audited_once_and_persons_are_not_audited(): void
    {
        $this->syncAttendees([$this->externalAttendee(1), $this->externalAttendee(2)]);

        $this->assertSame(1, AuditLog::query()->where('action', AuditAction::EVEN3_SYNC_TRIGGERED)->count());
        $this->assertSame(0, AuditLog::query()->where('action', AuditAction::PERSON_CREATED)->count());
        $this->assertSame($this->event->id, AuditLog::query()->where('action', AuditAction::EVEN3_SYNC_TRIGGERED)->sole()->event_id);
    }

    public function test_consultant_sees_sync_summary_without_warning_details(): void
    {
        $this->syncAttendees([$this->externalAttendee(1, ['event_id' => 999])]);
        $run = IntegrationSyncRun::query()->sole();
        $consultant = $this->userWithRole(RoleCode::Consultant);

        $this->actingAs($consultant)->getJson("/api/v1/integration-sync-runs/{$run->id}")
            ->assertOk()->assertJsonPath('data.warning_codes.EVENT_MISMATCH', 1)->assertJsonMissingPath('data.warnings');
        $this->actingAs($this->adminUser)->getJson("/api/v1/integration-sync-runs/{$run->id}")
            ->assertOk()->assertJsonPath('data.warnings.0.code', 'EVENT_MISMATCH')->assertJsonPath('data.warnings.0.external_id', '1');
        $this->actingAs($consultant)->getJson("/api/v1/events/{$this->event->id}/integrations/even3/sync-runs")
            ->assertOk()->assertJsonMissingPath('data.0.warnings')->assertJsonPath('meta.total', 1);
    }
}
