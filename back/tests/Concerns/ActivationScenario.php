<?php

namespace Tests\Concerns;

use App\Domain\Integrations\Enums\RegistrationStatus;
use App\Domain\Integrations\Enums\TicketCategory;
use App\Models\Event;
use App\Models\EventIntegration;
use App\Models\EventRegistration;
use App\Models\ExternalTicketType;
use App\Models\Person;
use App\Notifications\AccountActivationNotification;
use Illuminate\Support\Facades\Notification;

/**
 * Cenário do primeiro acesso: evento com integração Even3 e entradas mapeadas por external_ticket_id.
 */
trait ActivationScenario
{
    use Even3Fakes;

    protected Event $event;

    protected EventIntegration $integration;

    /** @var array<string, ExternalTicketType> */
    protected array $tickets = [];

    protected function buildActivationScenario(): void
    {
        $this->configureEven3();
        $this->event = Event::factory()->create();
        $this->integration = $this->even3Integration($this->event);
        $this->tickets = [
            // Nome "Público" de propósito em outra categoria: a regra é o mapeamento, nunca o texto.
            'public' => $this->ticketType($this->integration, '10', 'Ingresso geral', TicketCategory::Public),
            'participant' => $this->ticketType($this->integration, '20', 'Participante', TicketCategory::Participant),
            'juror' => $this->ticketType($this->integration, '30', 'Jurado', TicketCategory::Juror),
            'unmapped' => $this->ticketType($this->integration, '40', 'Público'),
        ];
    }

    protected function person(string $email, array $attributes = []): Person
    {
        return Person::factory()->create(['email' => $email] + $attributes);
    }

    protected function inscribe(Person $person, string $ticket = 'public', RegistrationStatus $status = RegistrationStatus::Confirmed, array $attributes = [], ?EventIntegration $integration = null): EventRegistration
    {
        static $sequence = 0;
        $sequence++;
        $integration ??= $this->integration;
        $registration = new EventRegistration(array_replace([
            'event_id' => $integration->event_id, 'event_integration_id' => $integration->id, 'person_id' => $person->id,
            'external_attendee_id' => "act-{$sequence}", 'external_ticket_type_id' => $this->tickets[$ticket]->id ?? null,
            'source_name' => $person->full_name, 'source_email' => $person->email,
        ], $attributes));
        $registration->forceFill(['status' => $status, 'status_changed_at' => now()])->save();

        return $registration;
    }

    protected function voterCandidate(string $email = 'publico@teste.local'): Person
    {
        $person = $this->person($email);
        $this->inscribe($person);

        return $person;
    }

    protected function requestActivation(string $email)
    {
        return $this->postJson('/api/v1/auth/activation/request', ['email' => $email]);
    }

    protected function completeActivation(string $token, string $password = 'senhaNova123', ?string $confirmation = null)
    {
        return $this->postJson('/api/v1/auth/activation/complete', [
            'token' => $token, 'password' => $password, 'password_confirmation' => $confirmation ?? $password,
        ]);
    }

    /**
     * Token bruto enviado no e-mail de primeiro acesso (capturado da notificação falsa).
     */
    protected function activationTokenFor(string $email): string
    {
        $tokens = $this->activationTokensFor($email);
        $this->assertNotEmpty($tokens, "Nenhum e-mail de primeiro acesso para {$email}");

        return end($tokens);
    }

    /**
     * Todos os tokens enviados a um e-mail, na ordem de envio.
     *
     * @return list<string>
     */
    protected function activationTokensFor(string $email): array
    {
        $tokens = [];
        Notification::assertSentOnDemand(AccountActivationNotification::class, function (AccountActivationNotification $notification, array $channels, object $notifiable) use ($email, &$tokens) {
            $match = ($notifiable->routes['mail'] ?? null) === $email;
            if ($match) {
                $tokens[] = $notification->token;
            }

            return $match;
        });

        return $tokens;
    }
}
