<?php

namespace App\Console\Commands;

use App\Models\AccountActivationToken;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Password;

/**
 * Remove tokens vencidos de primeiro acesso e de redefinição de senha.
 * Em produção, executar periodicamente (definido na Fase 12).
 */
class PruneAuthTokens extends Command
{
    protected $signature = 'hacklab:prune-auth-tokens';

    protected $description = 'Remove tokens vencidos de primeiro acesso e de redefinição de senha';

    public function handle(): int
    {
        $activation = AccountActivationToken::query()->where('expires_at', '<', now())->delete();
        Password::broker()->getRepository()->deleteExpired();

        $this->info("Tokens de primeiro acesso vencidos removidos: {$activation}. Tokens de redefinição vencidos removidos.");

        return self::SUCCESS;
    }
}
