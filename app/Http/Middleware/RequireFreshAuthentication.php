<?php

// Author: ramanpal singh | URL: https://kwebby.com

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireFreshAuthentication
{
    public function handle(Request $request, Closure $next): Response
    {
        if (time() - (int) $request->session()->get('auth.confirmed_at', 0) > 900) {
            return response()->json(['message' => 'Confirm your password to continue.', 'confirmation_required' => true], 423);
        }

        return $next($request);
    }
}
