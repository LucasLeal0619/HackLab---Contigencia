<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

/**
 * Envia um e-mail de teste (sem token, sem dado pessoal) para validar o SMTP configurado.
 * Mostra a configuração em uso sem a senha. Usar só com um endereço próprio e autorizado.
 */
class MailTest extends Command
{
    protected $signature = 'hacklab:mail-test {email : destinatário do teste (um endereço próprio)}';

    protected $description = 'Envia um e-mail de teste pelo mailer configurado (sem token)';

    public function handle(): int
    {
        $to = trim((string) $this->argument('email'));
        if (filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            $this->error('E-mail inválido.');

            return self::FAILURE;
        }

        $mailer = (string) config('mail.default');
        $smtp = config('mail.mailers.smtp');
        $this->table(['Item', 'Valor'], [
            ['Mailer', $mailer],
            ['Host', $mailer === 'smtp' ? $smtp['host'] : '-'],
            ['Porta', $mailer === 'smtp' ? (string) $smtp['port'] : '-'],
            ['Scheme', $mailer === 'smtp' ? ($smtp['scheme'] ?: 'smtp (STARTTLS se oferecido)') : '-'],
            ['TLS obrigatório', $mailer === 'smtp' ? ($smtp['require_tls'] ? 'sim' : 'não') : '-'],
            ['Usuário', $mailer === 'smtp' ? ($smtp['username'] ? 'definido' : 'vazio') : '-'],
            ['Remetente', config('mail.from.address').' ('.config('mail.from.name').')'],
        ]);

        if ($mailer !== 'smtp') {
            $this->warn("O mailer \"{$mailer}\" não entrega e-mail de verdade (log/array). Para o teste real use MAIL_MAILER=smtp.");
        }

        try {
            Mail::raw(
                "Este é um e-mail de teste do HackLab.\n\nSe você recebeu esta mensagem, o envio de e-mails está funcionando. Nenhuma ação é necessária.",
                fn (Message $message) => $message->to($to)->subject('Teste de envio do HackLab'),
            );
        } catch (TransportExceptionInterface $e) {
            $this->error('Falha no envio: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info("E-mail de teste entregue ao servidor de envio para {$to}. Confira a caixa de entrada e o spam.");

        return self::SUCCESS;
    }
}
