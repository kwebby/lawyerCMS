<?php

// Author: ramanpal singh | URL: https://kwebby.com

namespace App\Support;

use Illuminate\Support\Facades\Http;

/** Cloudflare Turnstile bot checks shared by the public analyzer and website enquiry forms. */
final class Turnstile
{
    public const ORIGIN = 'https://challenges.cloudflare.com';

    public function configured(): bool
    {
        return (bool) config('services.turnstile.site_key') && (bool) config('services.turnstile.secret');
    }

    public function verify(string $token, ?string $ip): bool
    {
        $result = Http::asForm()->timeout(10)->post(self::ORIGIN.'/turnstile/v0/siteverify', ['secret' => config('services.turnstile.secret'), 'response' => $token, 'remoteip' => $ip])->throw()->json();

        return ($result['success'] ?? false) && ($result['hostname'] ?? '') === parse_url(config('app.url'), PHP_URL_HOST);
    }
}
