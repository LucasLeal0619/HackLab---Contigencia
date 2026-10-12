<?php

namespace Tests\Feature\Integrations;

use App\Domain\Audit\AuditAction;
use App\Domain\Integrations\Enums\RegistrationStatus;
use App\Domain\Integrations\Enums\TicketCategory;
use App\Domain\Participants\Enums\ParticipantStatus;
use App\Domain\Users\Enums\RoleCode;
use App\Models\AuditLog;
use App\Models\Event;
use App\Models\EventIntegration;
use App\Models\EventRegistration;
use App\Models\ExternalTicketType;
use App\Models\Juror;
use App\Models\Participant;
use App\Models\Person;
use App\Models\SchoolClass;
use App\Models\Team;
use App\Models\User;
use Tests\Concerns\Even3Fakes;
use Tests\DatabaseTestCase;

/**
 * Inscrições (consulta, filtros, vínculos derivados sem conceder nada) e materialização explícita e
 * idempotente de participantes (CONFIRMED + PARTICIPANT).
 */
class EventRegistrationsTest extends DatabaseTestCase
{
    use Even3Fakes;

    private Event $event;

    private EventIntegration $integration;

    private User $adminUser;

    /** @var array<string, ExternalTicketType> */
    private array $tickets = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureEven3();
        $this->event = Event::factory()->create(['timezone' => 'America/Sao_Paulo']);
        $this->integration = $this->even3Integration($this->event);
        $this->tickets = [
            'participant' => $this->ticketType($this->integration, '10', 'Participante', TicketCategory::Participant),
            'public' => $this->ticketType($this->integration, '20', 'Público', TicketCategory::Public),
            'juror' => $this->ticketType($this->integration, '30', 'Jurado', TicketCategory::Juror),
            'unmapped' => $this->ticketType($this->integration, '40', 'Nova entrada'),
        ];
        $this->adminUser = $this->admin();
    }

    private function registration(string $name, string $ticket, RegistrationStatus $status, array $attributes = []): EventRegistration
    {
        // E-mail determinístico: a busca textual também olha o e-mail da Person (o aleatório da factory deixava o teste instável).
        $person = Person::factory()->create(['full_name' => $name, 'email' => strtolower(str_replace(' ', '.', $name)).'@hacklab.test']);
        $registration = new EventRegistration(array_replace([
            'event_id' => $this->event->id, 'event_integration_id' => $this->integration->id, 'person_id' => $person->id,
            'external_attendee_id' => (string) $person->id, 'external_ticket_type_id' => $this->tickets[$ticket]->id,
            'source_name' => $name, 'source_email' => strtolower(str_replace(' ', '.', $name)).'@teste.even3',
        ], $attributes));
        $registration->forceFill(['status' => $status, 'status_changed_at' => now()])->save();

        return $registration;
    }

    private function materialize(?User $user = null)
    {
        return $this->actingAs($user ?? $this->adminUser)->postJson("/api/v1/events/{$this->event->id}/event-registrations/materialize-participants");
    }

    public function test_materialization_creates_only_confirmed_participant_registrations(): void
    {
        $confirmed = $this->registration('Aluno Confirmado', 'participant', RegistrationStatus::Confirmed);
        $this->registration('Aluno Pendente', 'participant', RegistrationStatus::Pending);
        $this->registration('Aluno Cancelado', 'participant', RegistrationStatus::Cancelled);
        $this->registration('Público Confirmado', 'public', RegistrationStatus::Confirmed);
        $this->registration('Jurado Confirmado', 'juror', RegistrationStatus::Confirmed);
        $this->registration('Sem Mapeamento', 'unmapped', RegistrationStatus::Confirmed);

        $this->materialize()->assertOk()->assertJsonPath('data', ['eligible' => 1, 'created' => 1, 'already_existing' => 0]);

        $participant = Participant::query()->sole();
        $this->assertSame($confirmed->person_id, $participant->person_id);
        $this->assertSame(ParticipantStatus::Available, $participant->status);
        $this->assertNull($participant->class_id);
        $this->assertSame(0, Juror::query()->count(), 'JUROR não cria Juror');

        // Idempotente: rodar de novo não cria nem altera.
        $this->materialize()->assertOk()->assertJsonPath('data', ['eligible' => 1, 'created' => 0, 'already_existing' => 1]);
        $this->assertSame(1, Participant::query()->count());
        $this->assertSame(2, AuditLog::query()->where('action', AuditAction::EVEN3_PARTICIPANTS_MATERIALIZED)->count());
        $this->assertSame(0, AuditLog::query()->where('action', AuditAction::PARTICIPANT_CREATED)->count());
    }

    public function test_existing_participant_is_never_changed(): void
    {
        $registration = $this->registration('Aluno', 'participant', RegistrationStatus::Confirmed);
        $class = SchoolClass::factory()->for($this->event)->create();
        $existing = Participant::factory()->for($this->event)->create(['person_id' => $registration->person_id, 'status' => ParticipantStatus::Available, 'class_id' => $class->id]);
        $team = Team::factory()->for($this->event)->create();
        $this->actingAs($this->adminUser)->postJson("/api/v1/teams/{$team->id}/members", ['participant_id' => $existing->id])->assertSuccessful();
        $existing->forceFill(['status' => ParticipantStatus::Unavailable])->save();
        $before = $existing->fresh()->toArray();

        $this->materialize()->assertOk()->assertJsonPath('data.created', 0);

        $this->assertSame($before, $existing->fresh()->toArray());
        $this->assertTrue($existing->activeMembership()->exists());
    }

    public function test_cancelling_externally_does_not_remove_participant_or_membership(): void
    {
        $registration = $this->registration('Aluno', 'participant', RegistrationStatus::Confirmed);
        $this->materialize()->assertOk();
        $participant = Participant::query()->sole();

        $registration->forceFill(['status' => RegistrationStatus::Cancelled])->save();
        $this->materialize()->assertOk()->assertJsonPath('data.eligible', 0);

        $this->assertSame(ParticipantStatus::Available, $participant->fresh()->status);
    }

    public function test_materialization_requires_integrations_and_participants_manage(): void
    {
        $this->materialize($this->userWithRole(RoleCode::Consultant))->assertForbidden();
        $this->materialize($this->userWithRole(RoleCode::Voter))->assertForbidden();
        $this->assertSame(0, Participant::query()->count());
    }

    public function test_listing_filters_and_derived_links_without_granting_anything(): void
    {
        $ana = $this->registration('Ana', 'participant', RegistrationStatus::Confirmed, ['identity_mismatch' => true]);
        $bia = $this->registration('Bia', 'juror', RegistrationStatus::Pending, ['check_in_code' => '777']);
        $caio = $this->registration('Caio', 'unmapped', RegistrationStatus::Confirmed);
        Participant::factory()->for($this->event)->create(['person_id' => $ana->person_id]);
        Juror::factory()->for($this->event)->create(['person_id' => $bia->person_id]);
        $usersBefore = User::query()->count();

        $ids = fn (string $query) => array_column($this->actingAs($this->adminUser)->getJson("/api/v1/events/{$this->event->id}/event-registrations?{$query}")->assertOk()->json('data'), 'id');

        $this->assertSame([$ana->id, $bia->id, $caio->id], $ids('per_page=10'));
        $this->assertSame([$bia->id], $ids('search=777'));
        $this->assertSame([$ana->id], $ids('search=an'));
        $this->assertSame([$bia->id], $ids('status=PENDING'));
        $this->assertSame([$ana->id, $caio->id], $ids('confirmed=true'));
        $this->assertSame([$bia->id], $ids('internal_category=JUROR'));
        $this->assertSame([$caio->id], $ids("external_ticket_type_id={$this->tickets['unmapped']->id}"));
        $this->assertSame([$ana->id], $ids('has_participant=true'));
        $this->assertSame([$bia->id, $caio->id], $ids('has_participant=false'));
        $this->assertSame([$ana->id], $ids('identity_mismatch=true'));
        $this->assertSame([$caio->id], $ids('unmapped=true'));
        $this->actingAs($this->adminUser)->getJson("/api/v1/events/{$this->event->id}/event-registrations?status=INVALIDO")->assertUnprocessable();

        $item = $this->actingAs($this->adminUser)->getJson("/api/v1/events/{$this->event->id}/event-registrations?search=Bia")->json('data.0');
        $this->assertSame('JUROR', $item['ticket']['internal_category']);
        $this->assertNotNull($item['links']['juror']);
        $this->assertNull($item['links']['participant']);
        $this->assertNull($item['links']['user']);
        $this->assertSame($usersBefore, User::query()->count(), 'consultar não concede nada');

        $this->actingAs($this->adminUser)->getJson("/api/v1/event-registrations/{$bia->id}")->assertOk()
            ->assertJsonPath('data.external.check_in_code', '777')->assertJsonPath('data.attendance', []);
    }
}
