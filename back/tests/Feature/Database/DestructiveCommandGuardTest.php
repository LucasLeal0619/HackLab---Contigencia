<?php

namespace Tests\Feature\Database;

use App\Support\Database\DestructiveCommandGuard;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\DatabaseTestCase;

/**
 * migrate:fresh/refresh/reset/rollback e db:wipe só rodam em banco "_test". O hacklab_dev (e a produção)
 * não podem ser apagados por acidente; migrate normal continua liberado.
 *
 * Os comandos bloqueados são chamados com --database=guard_probe (conexão inexistente): se o bloqueio
 * falhasse, o comando pararia na conexão desconhecida — nunca apagaria um banco de verdade.
 */
class DestructiveCommandGuardTest extends DatabaseTestCase
{
    private const PROBE = 'guard_probe';

    protected function tearDown(): void
    {
        // Volta ao guard real da suíte (hacklab_test → liberado), para o RefreshDatabase das outras classes.
        $this->app->forgetInstance(DestructiveCommandGuard::class);
        $this->app->make(DestructiveCommandGuard::class)->apply();
        parent::tearDown();
    }

    /**
     * @return array<string, array{string, string, bool, bool}>
     */
    public static function decisions(): array
    {
        return [
            'testes no hacklab_test' => ['testing', 'hacklab_test', false, true],
            'local no hacklab_test' => ['local', 'hacklab_test', false, true],
            'desenvolvimento no hacklab_dev' => ['local', 'hacklab_dev', false, false],
            'testes apontando para o hacklab_dev' => ['testing', 'hacklab_dev', false, false],
            'hacklab_dev com liberação explícita' => ['local', 'hacklab_dev', true, true],
            'produção' => ['production', 'postgres', false, false],
            'produção com liberação' => ['production', 'postgres', true, false],
            'produção com nome _test' => ['production', 'hacklab_test', false, false],
            'banco sem nome (DB_URL)' => ['local', '', false, false],
        ];
    }

    #[DataProvider('decisions')]
    public function test_decides_by_environment_and_database_name(string $environment, string $database, bool $override, bool $allowed): void
    {
        $this->assertSame($allowed, (new DestructiveCommandGuard($environment, $database, $override))->allowsDestructiveCommands());
    }

    public function test_the_suite_runs_on_hacklab_test_without_any_override(): void
    {
        $this->assertTrue($this->app->environment('testing'));
        $this->assertSame('hacklab_test', config('database.connections.'.config('database.default').'.database'));
        $this->assertSame('hacklab', config('database.connections.pgsql.search_path'));
        $this->assertSame('hacklab_test', DB::scalar('select current_database()'));
        $this->assertSame('hacklab', DB::scalar('select current_schema()'));
        $this->assertFalse((bool) config('database.allow_destructive_commands'), 'a suíte não pode depender de HACKLAB_ALLOW_DESTRUCTIVE_DB');
        $this->assertTrue($this->app->make(DestructiveCommandGuard::class)->allowsDestructiveCommands());
    }

    public function test_destructive_commands_are_blocked_on_the_dev_database(): void
    {
        $guard = new DestructiveCommandGuard('local', 'hacklab_dev', false);
        $this->useGuard($guard);

        foreach (DestructiveCommandGuard::COMMANDS as $command) {
            $exit = Artisan::call($command, ['--force' => true, '--database' => self::PROBE]);

            $this->assertSame(1, $exit, $command);
            $this->assertStringContainsString('prohibited from running', Artisan::output(), $command);
        }

        // No terminal (php artisan …), o listener de CommandStarting mostra esta explicação antes do aviso.
        $this->assertStringContainsString('Comando destrutivo bloqueado no banco "hacklab_dev"', $guard->explanation());
        $this->assertStringContainsString(DestructiveCommandGuard::OVERRIDE_ENV.'=true', $guard->explanation());
    }

    public function test_explicit_override_lets_the_command_through(): void
    {
        $this->useGuard(new DestructiveCommandGuard('local', 'hacklab_dev', true));

        // Liberado: o comando segue até a conexão (inexistente aqui) em vez de ser bloqueado.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Database connection ['.self::PROBE.'] not configured');

        Artisan::call('db:wipe', ['--force' => true, '--database' => self::PROBE]);
    }

    public function test_normal_migrate_is_not_blocked_on_the_dev_database(): void
    {
        $this->useGuard(new DestructiveCommandGuard('local', 'hacklab_dev', false));

        // Roda no hacklab_test já migrado: nada a fazer, mas não é bloqueado.
        $this->assertSame(0, Artisan::call('migrate', ['--force' => true]));
        $this->assertStringNotContainsString('bloqueado', Artisan::output());
        $this->assertNotContains('migrate', DestructiveCommandGuard::COMMANDS);
        $this->assertNotContains('db:seed', DestructiveCommandGuard::COMMANDS);
    }

    private function useGuard(DestructiveCommandGuard $guard): void
    {
        $this->app->instance(DestructiveCommandGuard::class, $guard);
        $guard->apply();
    }
}
