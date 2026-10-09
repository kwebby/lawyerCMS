<?php

// Author: ramanpal singh | URL: https://kwebby.com

use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\RequireFreshAuthentication;
use App\Http\Middleware\SecureWorkspace;
use App\Http\Middleware\TrustConfiguredProxies;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // TRUSTED_PROXIES (config auth.trusted_proxies) is read per request, so cached configuration applies.
        $middleware->replace(TrustProxies::class, TrustConfiguredProxies::class);
        $middleware->web(append: [HandleInertiaRequests::class, SecureWorkspace::class]);
        $middleware->alias(['fresh' => RequireFreshAuthentication::class]);
        $middleware->validateCsrfTokens(except: ['api/v1/payments/webhooks/*']);
        $middleware->redirectGuestsTo('/login');
        $middleware->trustHosts(at: fn () => ['^'.preg_quote((string) parse_url(config('app.url'), PHP_URL_HOST), '#').'$'], subdomains: false);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })
    ->booted(function (): void {
        // One account cannot be guessed at quickly from one address, and one address cannot lock everyone out.
        RateLimiter::for('login', fn (Request $request) => [
            Limit::perMinute(config('auth.login_attempts.per_account', 5))->by('account:'.hash('sha256', strtolower(trim((string) $request->input('email'))).'|'.$request->ip())),
            Limit::perMinute(config('auth.login_attempts.per_address', 30))->by('address:'.$request->ip()),
        ]);
    })->create();
