<?php

use App\Exceptions\ApiExceptionRenderer;
use App\Http\Middleware\ResolveClientIp;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Primeiro do stack global: resolve o IP real do cliente antes de qualquer leitura de $request->ip().
        $middleware->prepend(ResolveClientIp::class);

        // Sanctum SPA: requisições do frontend autenticam por cookie de sessão + CSRF.
        $middleware->statefulApi();
        $middleware->throttleApi();

        // API não redireciona para tela de login: responde 401 em JSON.
        $middleware->redirectGuestsTo(fn (Request $request) => $request->is('api/*') ? null : '/');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->render(function (Throwable $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return ApiExceptionRenderer::render($e);
        });
    })->create();
