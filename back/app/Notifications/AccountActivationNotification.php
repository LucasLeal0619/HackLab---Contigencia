<?php

namespace App\Notifications;

use App\Support\FrontendUrl;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * E-mail de primeiro acesso: link para a tela de ativação no frontend (FRONTEND_URL). Sem dados pessoais.
 */
class AccountActivationNotification extends Notification
{
    use Queueable;

    /** Layout do e-mail (saudação, rodapé, texto do link) em português: lang/pt_BR.json. */
    public $locale = 'pt_BR';

    public function __construct(#[\SensitiveParameter] public readonly string $token, private readonly int $minutes) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Ative seu acesso ao HackLab')
            ->greeting('Olá!')
            ->line('Recebemos uma solicitação de primeiro acesso ao HackLab para este e-mail.')
            ->action('Criar minha senha', FrontendUrl::to('ativar-conta', ['token' => $this->token]))
            ->line("Este link vale por {$this->minutes} minutos e só pode ser usado uma vez.")
            ->line('Se você não fez essa solicitação, ignore este e-mail. Nenhuma conta será criada.')
            ->salutation('Equipe HackLab');
    }
}
