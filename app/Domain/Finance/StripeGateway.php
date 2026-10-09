<?php

// Author: ramanpal singh | URL: https://kwebby.com

namespace App\Domain\Finance;

use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

final class StripeGateway implements PaymentGateway
{
    public function configured(): bool
    {
        return filled(config('services.stripe.secret')) && filled(config('services.stripe.webhook_secret'));
    }

    public function capabilities(): array
    {
        return ['checkout' => true, 'refunds' => true, 'partial_refunds' => true, 'webhooks' => true];
    }

    private function http()
    {
        abort_unless($this->configured(), 503, 'Stripe credentials and webhook signing secret are not configured.');

        return Http::withToken(config('services.stripe.secret'))->connectTimeout(5)->timeout(20)->acceptJson();
    }

    public function createCheckout(array $checkout, array $invoice): array
    {
        $base = rtrim(config('app.url'), '/');
        $result = $this->http()->asForm()->withHeaders(['Idempotency-Key' => $checkout['id']])->post('https://api.stripe.com/v1/checkout/sessions', [
            'mode' => 'payment', 'client_reference_id' => $checkout['id'], 'metadata' => ['invoice_id' => $invoice['id'], 'checkout_id' => $checkout['id']],
            'payment_intent_data' => ['metadata' => ['invoice_id' => $invoice['id'], 'checkout_id' => $checkout['id']]],
            'line_items' => [['price_data' => ['currency' => strtolower($invoice['currency']), 'unit_amount' => $this->amount($checkout['amount_minor'], $invoice['currency']), 'product_data' => ['name' => 'Invoice '.$invoice['number']]], 'quantity' => 1]],
            'success_url' => $base.'/app/invoices?checkout='.urlencode($checkout['id']), 'cancel_url' => $base.'/app/invoices',
        ])->throw()->json();
        abort_unless(! empty($result['id']) && ! empty($result['url']), 502, 'Stripe did not return a checkout.');

        return ['provider_id' => $result['id'], 'url' => $result['url'], 'status' => 'pending'];
    }

    public function status(string $providerId): array
    {
        return $this->http()->get('https://api.stripe.com/v1/checkout/sessions/'.rawurlencode($providerId))->throw()->json();
    }

    public function verifyWebhook(string $body, array $headers): array
    {
        abort_unless($this->configured(), 503, 'Stripe is not configured.');
        $header = $headers['stripe-signature'] ?? '';
        $timestamps = [];
        $signatures = [];
        foreach (explode(',', $header) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');
            if ($key === 't') {
                $timestamps[] = $value;
            } if ($key === 'v1') {
                $signatures[] = $value;
            }
        }
        abort_unless(count($timestamps) === 1 && ctype_digit($timestamps[0]) && abs(time() - (int) $timestamps[0]) <= 300, 400, 'Invalid or expired Stripe signature.');
        $expected = hash_hmac('sha256', $timestamps[0].'.'.$body, config('services.stripe.webhook_secret'));
        abort_unless(count(array_filter($signatures, fn ($signature) => hash_equals($expected, $signature))) > 0, 400, 'Invalid Stripe signature.');
        $event = json_decode($body, true);
        abort_unless(is_array($event) && isset($event['id'], $event['type'], $event['data']['object']), 400, 'Invalid Stripe event.');
        if (! empty($event['account'])) {
            abort_unless($event['account'] === config('services.stripe.account_id'), 400, 'Stripe account does not match this installation.');
        }

        return $event;
    }

    public function refund(string $captureId, string $amountMinor, string $currency, string $idempotencyKey): array
    {
        return $this->http()->asForm()->withHeaders(['Idempotency-Key' => $idempotencyKey])->post('https://api.stripe.com/v1/refunds', ['payment_intent' => $captureId, 'amount' => $this->amount($amountMinor, $currency)])->throw()->json();
    }

    /** Stripe's integer amount for app ISO 4217 minor units. */
    public function amount(string $minor, string $currency): string
    {
        [$divisor, $step, $rule] = $this->scale($currency);
        $minor = Money::minor($minor);
        if ($minor % $step !== 0) {
            throw ValidationException::withMessages(['amount_minor' => 'Stripe cannot take this '.$currency.' amount: '.$rule.' Record this payment manually instead.']);
        }

        return (string) intdiv($minor, $divisor);
    }

    /** App ISO 4217 minor units for a Stripe integer amount. */
    public function minor(string $amount, string $currency): string
    {
        [$divisor] = $this->scale($currency);
        abort_unless(preg_match('/^(0|[1-9][0-9]{0,11})$/D', $amount), 422, 'Invalid provider amount.');

        return (string) Money::minor((string) ((int) $amount * $divisor));
    }

    /**
     * [divisor, required step in ISO minor units, rule]. Stripe amounts use the smallest currency unit, which matches
     * ISO 4217 except for the cases below. ISK and UGX (documented both as zero-decimal and as two-decimal for backwards
     * compatibility) and other zero-, three- or four-decimal codes without a documented Stripe rule are refused, not guessed.
     */
    private function scale(string $currency): array
    {
        $exponent = Currency::exponent($currency);
        if ($currency === 'MGA') {
            return [100, 100, 'Stripe treats MGA as a zero-decimal currency, so only whole amounts are possible.'];
        }
        if (in_array($currency, ['HUF', 'TWD'], true)) {
            return [1, 100, 'Stripe pays out '.$currency.' in whole units only.'];
        }
        if (in_array($currency, ['BHD', 'JOD', 'KWD', 'OMR', 'TND'], true)) {
            return [1, 10, 'Stripe requires three-decimal amounts to end in 0.'];
        }
        if ($exponent === 2 || ($exponent === 0 && in_array($currency, ['BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'PYG', 'RWF', 'VND', 'VUV', 'XAF', 'XOF', 'XPF'], true))) {
            return [1, 1, ''];
        }
        throw ValidationException::withMessages(['currency' => 'Stripe checkout is not available for '.$currency.'. Record the payment manually.']);
    }
}
