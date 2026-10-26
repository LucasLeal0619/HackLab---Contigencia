<?php

namespace Tests\Feature\Auth;

use App\Domain\Users\Enums\RoleCode;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mime\Email;
use Tests\Concerns\ActivationScenario;
use Tests\DatabaseTestCase;

/**
 * Envio real de e-mail (SMTP em produção): falha do servidor de envio não muda a resposta pública,
 * o layout sai em português, o SMTP tem timeout e pode exigir STARTTLS, e há um comando de teste sem token.
 */
class MailDeliveryTest extends DatabaseTestCase
{
    use ActivationScenario;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildActivationScenario();
    }

    /** SMTP que recusa a conexão na hora (porta fechada no próprio computador). */
    private function unreachableSmtp(): void
    {
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => '127.0.0.1', 'mail.mailers.smtp.port' => 1, 'mail.mailers.smtp.timeout' => 2]);
    }

    /** @return list<Email> */
    private function sentEmails(): array
    {
        /** @var ArrayTransport $transport */
        $transport = Mail::mailer('array')->getSymfonyTransport();

        return $transport->messages()->map(fn (SentMessage $m) => $m->getOriginalMessage())->values()->all();
    }

    public function test_activation_request_answers_the_same_when_smtp_is_down(): void
    {
        Exceptions::fake();
        $this->unreachableSmtp();
        $this->voterCandidate();

        $this->requestActivation('publico@teste.local')
            ->assertStatus(202)
            ->assertExactJson(['message' => 'Se este e-mail puder acessar o HackLab, enviaremos as instruções.']);

        Exceptions::assertReported(TransportException::class);
    }

    public function test_password_forgot_answers_the_same_when_smtp_is_down(): void
    {
        Exceptions::fake();
        $this->unreachableSmtp();
        $this->userWithRole(RoleCode::Voter, ['email' => 'conta@teste.local']);

        $this->postJson('/api/v1/auth/password/forgot', ['email' => 'conta@teste.local'])
            ->assertStatus(202)
            ->assertExactJson(['message' => 'Se houver uma conta com este e-mail, enviaremos um link para redefinir a senha.']);

        Exceptions::assertReported(TransportException::class);
    }

    public function test_activation_email_layout_is_in_portuguese(): void
    {
        config(['mail.default' => 'array']);
        $this->voterCandidate();

        $this->requestActivation('publico@teste.local')->assertStatus(202);

        $emails = $this->sentEmails();
        $this->assertCount(1, $emails);
        $html = $emails[0]->getHtmlBody();
        $this->assertSame('Ative seu acesso ao HackLab', $emails[0]->getSubject());
        $this->assertStringContainsString('não funcionar, copie e cole o endereço abaixo no navegador', $html);
        $this->assertStringContainsString('Todos os direitos reservados.', $html);
        $this->assertStringNotContainsString("If you're having trouble", $html);
        $this->assertSame('en', app()->getLocale(), 'O idioma da aplicação não muda: só o do e-mail');
    }

    public function test_password_reset_email_layout_is_in_portuguese(): void
    {
        config(['mail.default' => 'array']);
        $this->userWithRole(RoleCode::Voter, ['email' => 'conta@teste.local']);

        $this->postJson('/api/v1/auth/password/forgot', ['email' => 'conta@teste.local'])->assertStatus(202);

        $emails = $this->sentEmails();
        $this->assertCount(1, $emails);
        $this->assertSame('Redefinição de senha do HackLab', $emails[0]->getSubject());
        $this->assertStringContainsString('Todos os direitos reservados.', $emails[0]->getHtmlBody());
    }

    public function test_smtp_transport_has_timeout_and_can_require_tls(): void
    {
        config(['mail.mailers.smtp.host' => 'smtp.exemplo.test', 'mail.mailers.smtp.port' => 2525, 'mail.mailers.smtp.require_tls' => true]);

        $transport = Mail::mailer('smtp')->getSymfonyTransport();

        $this->assertTrue($transport->isTlsRequired());
        $this->assertSame(10.0, $transport->getStream()->getTimeout());
    }

    public function test_mail_test_command_sends_a_message_without_token(): void
    {
        config(['mail.default' => 'array']);

        $this->artisan('hacklab:mail-test', ['email' => 'eu@teste.local'])->assertSuccessful();

        $emails = $this->sentEmails();
        $this->assertCount(1, $emails);
        $this->assertSame('eu@teste.local', $emails[0]->getTo()[0]->getAddress());
        $this->assertSame('Teste de envio do HackLab', $emails[0]->getSubject());
        $this->assertStringNotContainsString('token', strtolower($emails[0]->getTextBody()));
    }

    public function test_mail_test_command_rejects_invalid_address_and_reports_smtp_failure(): void
    {
        $this->artisan('hacklab:mail-test', ['email' => 'nao-e-email'])->assertFailed();

        $this->unreachableSmtp();
        $this->artisan('hacklab:mail-test', ['email' => 'eu@teste.local'])->assertFailed();
    }
}
