<?php

// Author: ramanpal singh | URL: https://kwebby.com

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class SecureWorkspace
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->hasSession() && ($user = $request->user())) {
            $epoch = $request->session()->get('auth.epoch');
            if ($epoch !== null && $epoch !== ($user->session_epoch ?? 0)) {
                Auth::logout();
                $request->session()->invalidate();

                return $request->expectsJson() ? response()->json(['message' => 'Session revoked.'], 401) : redirect('/login');
            }
            $staff = ! array_intersect(['client', 'prospect'], $user->roles ?? []);
            if (config('crm.require_mfa') && $staff && ! $request->session()->get('auth.mfa') && ! $request->is('mfa', 'mfa/*', 'logout', 'api/v1/auth/*')) {
                return $request->expectsJson() ? response()->json(['message' => 'Complete multi-factor authentication.', 'mfa_required' => true], 403) : redirect('/mfa');
            }
        }
        $response = $next($request);
        $private = $request->is('app*', 'portal*', 'api/*', 'login', 'register', 'setup', 'mfa', 'mfa/*', 'password/*', 'analyze/*', 'tools/results/*', 'tools/verify/*', 'tools/upload', 'preview*');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
        if ($request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000');
        }
        if ($private) {
            $response->headers->set('Cache-Control', 'private, no-store');
            $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        }
        if (! $response->headers->has('Content-Security-Policy')) {
            $response->headers->set('Content-Security-Policy', "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; font-src 'self' data:; connect-src 'self'; frame-src 'none'; object-src 'none'; base-uri 'self'; form-action 'self'");
        }

        return $response;
    }
}
