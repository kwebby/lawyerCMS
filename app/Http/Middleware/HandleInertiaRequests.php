<?php

// Author: ramanpal singh | URL: https://kwebby.com

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function share(Request $request): array
    {
        return array_merge(parent::share($request), ['csrf_token' => csrf_token(), 'errors' => fn () => $request->session()->get('errors')?->getBag('default')->messages() ?? []]);
    }
}
