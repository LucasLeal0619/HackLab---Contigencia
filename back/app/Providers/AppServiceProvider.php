<?php

namespace App\Providers;

use App\Integrations\Even3\Even3Client;
use App\Integrations\Even3\Even3Provider;
use App\Integrations\EventPlatform\EventPlatformProvider;
use App\Support\Database\DatabaseEnvironmentGuard;
use App\Support\Database\DestructiveCommandGuard;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Database\Events\ConnectionEstablished;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(DatabaseEnvironmentGuard::class, fn ($app) => new DatabaseEnvironmentGuard(
            environment: $app->environment(),
        ));

        $this->app->singleton(DestructiveCommandGuard::class, fn ($app) => new DestructiveCommandGuard(
            environment: $app->environment(),
            database: (string) $app['config']->get('database.connections.'.$app['config']->get('database.default').'.database'),
            override: (bool) $app['config']->get('database.allow_destructive_commands'),
        ));

        // Integração de inscrições: Even3 (V2). Lido da config a cada resolução (nunca guarda token em outro lugar).
        $this->app->bind(Even3Client::class, fn () => Even3Client::fromConfig());
        $this->app->bind(EventPlatformProvider::class, Even3Provider::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Event::listen(function (ConnectionEstablished $event): void {
            $this->app->make(DatabaseEnvironmentGuard::class)->check($event->connection);
        });

        // Comandos que apagam o banco só em banco de testes ("_test"): protege o hacklab_dev e a produção.
        $this->app->make(DestructiveCommandGuard::class)->apply();
        Event::listen(function (CommandStarting $event): void {
            $destructive = $this->app->make(DestructiveCommandGuard::class);
            if (in_array($event->command, DestructiveCommandGuard::COMMANDS, true) && ! $destructive->allowsDestructiveCommands()) {
                $event->output->writeln("<error>{$destructive->explanation()}</error>");
            }
        });

        // Atrás do proxy do Render: IP do cliente e HTTPS vêm dos X-Forwarded-* (lido da config, que pode estar em cache).
        $proxies = trim((string) config('app.trusted_proxies'));
        if ($proxies !== '') {
            TrustProxies::at($proxies === '*' ? '*' : array_map('trim', explode(',', $proxies)));
        }

        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(120)->by($request->user()?->id ?: $request->ip());
        });

        // Login: 5 tentativas por minuto por e-mail + IP.
        RateLimiter::for('login', function (Request $request) {
            return Limit::perMinute(5)->by(mb_strtolower((string) $request->input('email')).'|'.$request->ip());
        });

        // Primeiro acesso e esqueci a senha (públicos): 5/min por e-mail + IP e 20/h por IP.
        RateLimiter::for('auth-recovery', function (Request $request) {
            return [
                Limit::perMinute(5)->by('recovery|'.mb_strtolower(trim((string) $request->input('email'))).'|'.$request->ip()),
                Limit::perHour(20)->by('recovery-ip|'.$request->ip()),
            ];
        });

        // Conclusão de primeiro acesso e redefinição (token do link): 10/min por IP.
        RateLimiter::for('auth-recovery-complete', function (Request $request) {
            return Limit::perMinute(10)->by('recovery-complete|'.$request->ip());
        });

        // Webhook da Even3 (sem usuário autenticado): limite por IP.
        RateLimiter::for('webhooks', function (Request $request) {
            return Limit::perMinute(600)->by($request->ip());
        });

        Password::defaults(fn () => Password::min(8)->letters()->numbers());
    }
}
