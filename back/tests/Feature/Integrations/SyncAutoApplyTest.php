<?php

namespace Tests\Feature\Integrations;

use App\Domain\Audit\AuditAction;
use App\Domain\Integrations\Enums\TicketCategory;
use App\Models\AuditLog;
use App\Models\Event;
use App\Models\EventIntegration;
use App\Models\IntegrationSyncRun;
use App\Models\Juror;
use App\Models\Participant;
use App\Models\Person;
use App\Models\User;
use App\Models\VotingEligibility;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\Even3Fakes;
use Tests\DatabaseTestCase;

/**
 * Simplificação pré-deploy: o seguro é automático. Ao sincronizar, só o Jurado confiável vira Juror. Nada cria
 * Participant, conta, empresa ou elegibilidade, e o evento não muda. Repetir não duplica.
 */
class SyncAutoApplyTest extends DatabaseTestCase
{
    use Even3Fakes;

    private Event $event;

    private EventIntegration $integration;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureEven3();
        $this->event = Event::factory()->create(['name' => 'Nome Interno', 'start_date' => '2026-11-04', 'end_date' => '2026-11-06']);
        $this->integration = $this->even3Integration($this->event);
        $this->ticketType($this->integration, '20', 'Participante', TicketCategory::Participant);
        $this->ticketType($this->integration, '30', 'Jurado', TicketCategory::Juror);
        $this->ticketType($this->integration, '40', 'Público', TicketCategory::Public);
    }

    private function sync(array $body = ['scope' => 'ALL'])
    {
        return $this->actingAs($this->admin())->postJson("/api/v1/events/{$this->event->id}/integrations/even3/sync", $body)->assertCreated();
    }

    private function fake(array $attendees, string $title = 'Hackathon Teste'): void
    {
        $this->refakeHttp([
            self::EVEN3_BASE.'/events/'.self::EXTERNAL_EVENT_ID.'/tickets' => Http::response([$this->externalTicket(20, 'Participante'), $this->externalTicket(30, 'Jurado'), $this->externalTicket(40, 'Público')]),
            self::EVEN3_BASE.'/events/'.self::EXTERNAL_EVENT_ID => Http::response($this->externalEvent(self::EXTERNAL_EVENT_ID, $title)),
            self::EVEN3_BASE.'/attendees*' => Http::response($this->page($attendees)),
        ]);
    }

    public function test_trusted_juror_becomes_juror_without_account_and_only_once(): void
    {
        $this->fake([
            $this->externalAttendee(1, ['registration_category' => 'Jurado']),
            $this->externalAttendee(2, ['registration_category' => 'Jurado', 'is_confirmed' => false]),
        ]);

        $this->sync()->assertJsonPath('data.counters.jurors_created', 1);

        $juror = Juror::query()->where('event_id', $this->event->id)->sole();
        $this->assertSame('ACTIVE', $juror->status->value);
        $this->assertSame(1, User::query()->count(), 'só o admin: jurado não ganha conta pela sincronização');
        $this->assertSame(1, AuditLog::query()->where('action', AuditAction::JUROR_CREATED)->count());

        $this->sync()->assertJsonPath('data.counters.jurors_created', 0);
        $this->assertSame(1, Juror::query()->count());
    }

    public function test_existing_juror_is_preserved(): void
    {
        $this->fake([$this->externalAttendee(1, ['registration_category' => 'Jurado'])]);
        $this->sync(['scope' => 'ATTENDEES']);
        $juror = Juror::query()->sole();
        $juror->forceFill(['status' => 'INACTIVE'])->save();

        $this->sync()->assertJsonPath('data.counters.jurors_created', 0);

        $this->assertSame('INACTIVE', $juror->fresh()->status->value, 'inativado pelo Admin não é reativado');
    }

    public function test_conflicting_identity_never_becomes_juror_and_needs_attention(): void
    {
        // Pessoa já cadastrada com outro e-mail: o documento casa, o e-mail diverge → identity_mismatch.
        Person::factory()->create(['email' => 'outro@teste.local', 'document' => '00100100101']);
        $this->fake([$this->externalAttendee(1, ['registration_category' => 'Jurado'])]);

        $this->sync()->assertJsonPath('data.counters.jurors_created', 0);

        $this->assertSame(0, Juror::query()->count());
        $this->actingAs($this->admin())->getJson("/api/v1/events/{$this->event->id}/integrations/even3/attention")
            ->assertOk()->assertJsonPath('data.0.type', 'IDENTITY_CONFLICT');
    }

    public function test_sync_never_creates_participants_accounts_eligibility_or_changes_the_event(): void
    {
        $this->fake([
            $this->externalAttendee(1, ['registration_category' => 'Participante']),
            $this->externalAttendee(2, ['registration_category' => 'Público']),
        ], 'Nome Diferente na Even3');

        $this->sync();
        $this->sync();

        $this->assertSame(0, Participant::query()->count());
        $this->assertSame(0, User::query()->whereHas('role', fn ($q) => $q->where('code', '!=', 'ADMINISTRATOR'))->count(), 'nenhuma conta nasce da Even3');
        $this->assertSame(0, VotingEligibility::query()->count());
        $this->assertSame('Nome Interno', $this->event->fresh()->name, 'nome/datas são do HackLab');
    }

    public function test_automatic_flag_marks_the_run_as_scheduled(): void
    {
        $this->fake([]);

        $this->sync(['scope' => 'ALL', 'automatic' => true]);

        $this->assertSame('SCHEDULED', IntegrationSyncRun::query()->latest('id')->first()->trigger->value);
    }
}
