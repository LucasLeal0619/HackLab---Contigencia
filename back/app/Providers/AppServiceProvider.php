<?php

namespace App\Providers;

use App\Integrations\Even3\Even3Client;
use App\Integrations\Even3\Even3Provider;
use App\Integrations\EventPlatform\EventPlatformProvider;
use App\Support\Database\DatabaseEnvironmentGuard;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Events\ConnectionEstablished;
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

        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(120)->by($request->user()?->id ?: $request->ip());
        });

        // Login: 5 tentativas por minuto por e-mail + IP.
        RateLimiter::for('login', function (Request $request) {
            return Limit::perMinute(5)->by(mb_strtolower((string) $request->input('email')).'|'.$request->ip());
        });

        // Webhook da Even3 (sem usuário autenticado): limite por IP.
        RateLimiter::for('webhooks', function (Request $request) {
            return Limit::perMinute(600)->by($request->ip());
        });

        Password::defaults(fn () => Password::min(8)->letters()->numbers());
    }
}
