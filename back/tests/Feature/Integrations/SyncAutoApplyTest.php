<?php

namespace Tests\Feature\Integrations;

use App\Domain\Integrations\Enums\ReconciliationKind;
use App\Domain\Integrations\Enums\ReconciliationStatus;
use App\Domain\Integrations\Enums\TicketCategory;
use App\Models\Event;
use App\Models\EventDay;
use App\Models\EventIntegration;
use App\Models\IntegrationReconciliation;
use App\Models\IntegrationSyncRun;
use App\Models\Juror;
use App\Models\Person;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\Even3Fakes;
use Tests\DatabaseTestCase;

/**
 * Decisão de 20/10/2026: ao sincronizar, Jurados confirmados e nome/datas do evento entram sozinhos.
 * Conta nunca nasce da sincronização; ignorados não voltam; datas que quebrariam os dias não são aplicadas.
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
    }

    private function sync(array $body = ['scope' => 'ALL'])
    {
        return $this->actingAs($this->admin())->postJson("/api/v1/events/{$this->event->id}/integrations/even3/sync", $body)->assertCreated();
    }

    private function fake(array $attendees, string $title = 'Hackathon Teste'): void
    {
        $this->refakeHttp([
            self::EVEN3_BASE.'/events/'.self::EXTERNAL_EVENT_ID.'/tickets' => Http::response([$this->externalTicket(20, 'Participante'), $this->externalTicket(30, 'Jurado')]),
            self::EVEN3_BASE.'/events/'.self::EXTERNAL_EVENT_ID => Http::response($this->externalEvent(self::EXTERNAL_EVENT_ID, $title)),
            self::EVEN3_BASE.'/attendees*' => Http::response($this->page($attendees)),
        ]);
    }

    public function test_confirmed_jurors_become_jurors_without_accounts_and_only_once(): void
    {
        $this->fake([
            $this->externalAttendee(1, ['registration_category' => 'Jurado']),
            $this->externalAttendee(2, ['registration_category' => 'Jurado', 'is_confirmed' => false]),
        ]);

        $this->sync()->assertJsonPath('data.counters.jurors_created', 1);

        $juror = Juror::query()->where('event_id', $this->event->id)->sole();
        $this->assertSame('ACTIVE', $juror->status->value);
        $this->assertSame(1, User::query()->count(), 'só o admin: jurado não ganha conta pela sincronização');
        $this->assertSame(ReconciliationStatus::Resolved, IntegrationReconciliation::query()->where('kind', ReconciliationKind::Juror->value)->sole()->status);

        $this->sync()->assertJsonPath('data.counters.jurors_created', 0);
        $this->assertSame(1, Juror::query()->count());
    }

    public function test_ignored_juror_is_not_created(): void
    {
        $this->fake([$this->externalAttendee(1, ['registration_category' => 'Jurado'])]);
        $this->sync(['scope' => 'ATTENDEES']); // cria o jurado na 1ª vez
        Juror::query()->delete();
        IntegrationReconciliation::query()->delete();
        $person = Person::query()->where('email', 'pessoa1@teste.even3')->sole();
        $this->actingAs($this->admin())->postJson("/api/v1/events/{$this->event->id}/integrations/even3/reconciliation/jurors/{$person->id}/ignore")->assertOk();

        $this->sync()->assertJsonPath('data.counters.jurors_created', 0);

        $this->assertSame(0, Juror::query()->count());
    }

    public function test_event_name_and_dates_follow_even3(): void
    {
        $this->fake([], 'Hackathon Teste');

        $this->sync(['scope' => 'EVENT'])->assertJsonPath('data.counters.event_fields_applied', 1);

        // externalEvent: 2026-11-04 a 2026-11-06 (iguais) e título diferente.
        $this->assertSame('Hackathon Teste', $this->event->fresh()->name);
    }

    public function test_dates_that_would_leave_days_outside_are_not_applied_but_name_is(): void
    {
        EventDay::factory()->for($this->event)->create(['date' => '2026-11-03', 'day_number' => 1]);
        $this->event->forceFill(['start_date' => '2026-11-03'])->save();
        $this->fake([], 'Outro Nome');

        $run = $this->sync(['scope' => 'EVENT'])->json('data');

        $this->assertSame(1, $run['counters']['event_fields_applied']);
        $this->assertSame(['EVENT_DATES_NOT_APPLIED' => 1], $run['warning_codes']);
        $event = $this->event->fresh();
        $this->assertSame('Outro Nome', $event->name);
        $this->assertSame('2026-11-03', $event->start_date->toDateString(), 'dias nunca são movidos');
    }

    public function test_automatic_flag_marks_the_run_as_scheduled(): void
    {
        $this->fake([]);

        $this->sync(['scope' => 'ALL', 'automatic' => true]);

        $this->assertSame('SCHEDULED', IntegrationSyncRun::query()->latest('id')->first()->trigger->value);
    }
}
