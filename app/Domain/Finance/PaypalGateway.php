<?php

// Author: ramanpal singh | URL: https://kwebby.com

namespace App\Domain\Finance;

use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

final class PaypalGateway implements PaymentGateway
{
    public function configured(): bool
    {
        return filled(config('services.paypal.client_id')) && filled(config('services.paypal.secret')) && filled(config('services.paypal.webhook_id')) && filled(config('services.paypal.merchant_id'));
    }

    public function capabilities(): array
    {
        return ['checkout' => true, 'refunds' => true, 'partial_refunds' => true, 'webhooks' => true];
    }

    private function base(): string
    {
        return config('services.paypal.mode', 'sandbox') === 'live' ? 'https://api-m.paypal.com' : 'https://api-m.sandbox.paypal.com';
    }

    private function http()
    {
        abort_unless($this->configured(), 503, 'PayPal credentials, merchant ID, and webhook ID are not configured.');
        $token = Http::withBasicAuth(config('services.paypal.client_id'), config('services.paypal.secret'))->asForm()->connectTimeout(5)->timeout(20)->post($this->base().'/v1/oauth2/token', ['grant_type' => 'client_credentials'])->throw()->json('access_token');
        abort_unless(is_string($token) && $token !== '', 502, 'PayPal authorization failed.');

        return Http::withToken($token)->acceptJson()->connectTimeout(5)->timeout(20);
    }

    public function createCheckout(array $checkout, array $invoice): array
    {
        $result = $this->http()->withHeaders(['PayPal-Request-Id' => $checkout['id']])->post($this->base().'/v2/checkout/orders', [
            'intent' => 'CAPTURE', 'purchase_units' => [['reference_id' => $checkout['id'], 'custom_id' => $checkout['id'], 'invoice_id' => $invoice['number'].'-'.$checkout['id'], 'payee' => ['merchant_id' => config('services.paypal.merchant_id')], 'amount' => ['currency_code' => $invoice['currency'], 'value' => $this->decimal($checkout['amount_minor'], $invoice['currency'])]]],
            'payment_source' => ['paypal' => ['experience_context' => ['return_url' => rtrim(config('app.url'), '/').'/app/invoices?checkout='.urlencode($checkout['id']), 'cancel_url' => rtrim(config('app.url'), '/').'/app/invoices', 'user_action' => 'PAY_NOW']]],
        ])->throw()->json();
        $url = collect($result['links'] ?? [])->first(fn ($link) => in_array($link['rel'] ?? '', ['approve', 'payer-action'], true))['href'] ?? null;
        abort_unless(! empty($result['id']) && $url, 502, 'PayPal did not return an approval URL.');

        return ['provider_id' => $result['id'], 'url' => $url, 'status' => 'pending'];
    }

    public function status(string $providerId): array
    {
        return $this->http()->get($this->base().'/v2/checkout/orders/'.rawurlencode($providerId))->throw()->json();
    }

    public function capture(string $providerId, string $key): array
    {
        return $this->http()->withHeaders(['PayPal-Request-Id' => $key])->withBody('{}', 'application/json')->post($this->base().'/v2/checkout/orders/'.rawurlencode($providerId).'/capture')->throw()->json();
    }

    public function verifyWebhook(string $body, array $headers): array
    {
        $event = json_decode($body, true);
        abort_unless(is_array($event) && isset($event['id'], $event['event_type'], $event['resource']), 400, 'Invalid PayPal event.');
        $required = ['paypal-auth-algo', 'paypal-cert-url', 'paypal-transmission-id', 'paypal-transmission-sig', 'paypal-transmission-time'];
        foreach ($required as $name) {
            abort_unless(! empty($headers[$name]), 400, 'Missing PayPal signature header.');
        }
        // Verify remotely at a fixed PayPal URL; never fetch an attacker-supplied certificate URL.
        $result = $this->http()->post($this->base().'/v1/notifications/verify-webhook-signature', ['auth_algo' => $headers['paypal-auth-algo'], 'cert_url' => $headers['paypal-cert-url'], 'transmission_id' => $headers['paypal-transmission-id'], 'transmission_sig' => $headers['paypal-transmission-sig'], 'transmission_time' => $headers['paypal-transmission-time'], 'webhook_id' => config('services.paypal.webhook_id'), 'webhook_event' => $event])->throw()->json();
        abort_unless(($result['verification_status'] ?? '') === 'SUCCESS', 400, 'Invalid PayPal signature.');

        return $event;
    }

    public function refund(string $captureId, string $amountMinor, string $currency, string $idempotencyKey): array
    {
        return $this->http()->withHeaders(['PayPal-Request-Id' => $idempotencyKey])->post($this->base().'/v2/payments/captures/'.rawurlencode($captureId).'/refund', ['amount' => ['currency_code' => $currency, 'value' => $this->decimal($amountMinor, $currency)]])->throw()->json();
    }

    public function decimal(string $minor, string $currency): string
    {
        $exponent = $this->exponent($currency);
        $minor = (string) Money::minor($minor);
        if ($exponent === 0) {
            return $minor;
        }

        return substr(str_pad($minor, 3, '0', STR_PAD_LEFT), 0, -2).'.'.substr(str_pad($minor, 3, '0', STR_PAD_LEFT), -2);
    }

    public function minor(string $amount, string $currency): string
    {
        $exponent = $this->exponent($currency);
        abort_unless(preg_match($exponent === 0 ? '/^[0-9]+(?:\.0{1,2})?$/D' : '/^[0-9]+(?:\.[0-9]{1,2})?$/D', $amount), 422, 'Invalid provider amount.');
        [$whole, $fraction] = array_pad(explode('.', $amount), 2, '');

        return (string) Money::minor(ltrim($whole.($exponent ? str_pad($fraction, 2, '0') : ''), '0') ?: '0');
    }

    private function exponent(string $currency): int
    {
        if (in_array($currency, ['HUF', 'JPY', 'TWD'], true)) {
            return 0;
        }
        if (in_array($currency, ['AUD', 'BRL', 'CAD', 'CNY', 'CZK', 'DKK', 'EUR', 'HKD', 'ILS', 'MYR', 'MXN', 'NZD', 'NOK', 'PHP', 'PLN', 'GBP', 'SGD', 'SEK', 'CHF', 'THB', 'USD'], true)) {
            return 2;
        }
        throw ValidationException::withMessages(['currency' => 'This currency is not supported by the PayPal checkout adapter.']);
    }
}
