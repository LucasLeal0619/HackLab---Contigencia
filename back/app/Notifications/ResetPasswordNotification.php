<?php

namespace App\Notifications;

use App\Support\FrontendUrl;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * E-mail de redefinição de senha: link para a tela do frontend (FRONTEND_URL), não para uma rota do Laravel.
 */
class ResetPasswordNotification extends Notification
{
    use Queueable;

    /** Layout do e-mail (saudação, rodapé, texto do link) em português: lang/pt_BR.json. */
    public $locale = 'pt_BR';

    public function __construct(#[\SensitiveParameter] public readonly string $token) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $minutes = (int) config('auth.passwords.users.expire', 60);

        return (new MailMessage)
            ->subject('Redefinição de senha do HackLab')
            ->greeting('Olá!')
            ->line('Recebemos uma solicitação para redefinir a senha da sua conta no HackLab.')
            ->action('Redefinir senha', FrontendUrl::to('redefinir-senha', ['token' => $this->token, 'email' => $notifiable->getEmailForPasswordReset()]))
            ->line("Este link vale por {$minutes} minutos e só pode ser usado uma vez.")
            ->line('Se você não fez essa solicitação, ignore este e-mail. Sua senha continua a mesma.')
            ->salutation('Equipe HackLab');
    }
}
