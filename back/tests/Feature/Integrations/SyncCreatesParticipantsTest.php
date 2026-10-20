<?php

namespace Tests\Feature\Integrations;

use App\Domain\Audit\AuditAction;
use App\Domain\Integrations\Enums\TicketCategory;
use App\Models\AuditLog;
use App\Models\Event;
use App\Models\Participant;
use App\Models\User;
use Tests\Concerns\Even3Fakes;
use Tests\DatabaseTestCase;

/**
 * Complemento 11.2: ao sincronizar, os inscritos confirmados em entrada PARTICIPANT viram Participant sozinhos
 * (sem botão). Idempotente, sem tocar em quem já existe, sem criar Jurado/Empresa/Usuário.
 */
class SyncCreatesParticipantsTest extends DatabaseTestCase
{
    use Even3Fakes;

    private Event $event;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureEven3();
        $this->event = Event::factory()->create();
        $integration = $this->even3Integration($this->event);
        $this->ticketType($integration, '20', 'Participante', TicketCategory::Participant);
        $this->ticketType($integration, '30', 'Jurado', TicketCategory::Juror);
    }

    private function sync(string $scope = 'ALL')
    {
        return $this->actingAs($this->admin())->postJson("/api/v1/events/{$this->event->id}/integrations/even3/sync", ['scope' => $scope])->assertCreated();
    }

    public function test_sync_creates_confirmed_participants_and_is_idempotent(): void
    {
        $this->fakeEven3(
            [$this->externalTicket(20, 'Participante'), $this->externalTicket(30, 'Jurado')],
            [
                $this->externalAttendee(1),
                $this->externalAttendee(2, ['is_confirmed' => false]),
                $this->externalAttendee(3, ['registration_category' => 'Jurado']),
            ],
        );

        $this->sync()->assertJsonPath('data.counters.participants_created', 1);

        $this->assertSame(1, Participant::query()->where('event_id', $this->event->id)->count());
        $this->assertSame(1, AuditLog::query()->where('action', AuditAction::EVEN3_PARTICIPANTS_MATERIALIZED)->count());
        $this->assertSame(1, User::query()->count(), 'só o admin: nenhum usuário criado pela sincronização');

        // Segunda vez: nada novo, nenhum log novo.
        $this->sync()->assertJsonPath('data.counters.participants_created', 0);
        $this->assertSame(1, Participant::query()->count());
        $this->assertSame(1, AuditLog::query()->where('action', AuditAction::EVEN3_PARTICIPANTS_MATERIALIZED)->count());
    }

    public function test_existing_participant_is_not_changed(): void
    {
        $this->fakeEven3([$this->externalTicket(20, 'Participante')], [$this->externalAttendee(1)]);
        $this->sync();
        $participant = Participant::query()->sole();
        $participant->forceFill(['status' => 'UNAVAILABLE'])->save();

        $this->sync();

        $this->assertSame('UNAVAILABLE', $participant->fresh()->status->value);
    }

    public function test_event_only_sync_does_not_create_participants(): void
    {
        $this->fakeEven3([$this->externalTicket(20, 'Participante')], [$this->externalAttendee(1)]);

        $this->sync('EVENT')->assertJsonMissingPath('data.counters.participants_created');

        $this->assertSame(0, Participant::query()->count());
    }
}
