<?php

// Author: ramanpal singh | URL: https://kwebby.com

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies;

/** Honour forwarded client/protocol headers only from the reverse proxies listed in TRUSTED_PROXIES; none by default. */
class TrustConfiguredProxies extends TrustProxies
{
    protected function proxies()
    {
        // Read at request time so a cached configuration applies; an empty value trusts no proxy.
        return trim((string) config('auth.trusted_proxies', '')) ?: null;
    }
}
