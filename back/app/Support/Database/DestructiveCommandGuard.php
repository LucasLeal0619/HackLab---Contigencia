<?php

namespace App\Support\Database;

use Illuminate\Support\Facades\DB;

/**
 * Bloqueia os comandos que apagam o banco (migrate:fresh, migrate:refresh, migrate:reset,
 * migrate:rollback e db:wipe) em qualquer banco que não seja de testes.
 *
 * - banco terminado em `_test` (fora de production): liberado — é onde os testes recriam o schema;
 * - qualquer outro (hacklab_dev, produção): bloqueado. `migrate` e `migrate --seed` continuam normais;
 * - para recriar o hacklab_dev de propósito: HACKLAB_ALLOW_DESTRUCTIVE_DB=true só naquele comando.
 *   Em production a liberação é ignorada.
 *
 * Usa o bloqueio nativo do Laravel (Prohibitable): o comando sai com erro antes de abrir a conexão.
 */
class DestructiveCommandGuard
{
    public const COMMANDS = ['migrate:fresh', 'migrate:refresh', 'migrate:reset', 'migrate:rollback', 'db:wipe'];

    public const OVERRIDE_ENV = 'HACKLAB_ALLOW_DESTRUCTIVE_DB';

    public function __construct(
        private readonly string $environment,
        private readonly string $database,
        private readonly bool $override,
    ) {}

    public function allowsDestructiveCommands(): bool
    {
        if ($this->environment === 'production') {
            return false;
        }

        return str_ends_with($this->database, '_test') || $this->override;
    }

    public function apply(): void
    {
        DB::prohibitDestructiveCommands(! $this->allowsDestructiveCommands());
    }

    public function explanation(): string
    {
        return sprintf(
            'Comando destrutivo bloqueado no banco "%s": ele apagaria configurações de integração e dados sincronizados. '
            .'Testes usam o hacklab_test. Para recriar este banco de propósito: %s=true só neste comando '
            .'(ex.: docker compose exec -e %s=true app php artisan migrate:fresh --seed).',
            $this->database,
            self::OVERRIDE_ENV,
            self::OVERRIDE_ENV,
        );
    }
}
