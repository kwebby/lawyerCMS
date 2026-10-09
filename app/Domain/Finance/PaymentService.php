<?php

// Author: ramanpal singh | URL: https://kwebby.com

namespace App\Domain\Finance;

use App\Contracts\RecordStore;
use App\Support\Access;
use App\Support\Audit;
use App\Support\Outbox;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final class PaymentService
{
    public function __construct(private RecordStore $store, private Access $access, private InvoiceService $invoices, private StripeGateway $stripe, private PaypalGateway $paypal, private Audit $audit, private Outbox $outbox) {}

    public function gateways(): array
    {
        return ['stripe' => ['configured' => $this->stripe->configured(), 'capabilities' => $this->stripe->capabilities()], 'paypal' => ['configured' => $this->paypal->configured(), 'capabilities' => $this->paypal->capabilities()]];
    }

    private function gateway(string $provider): PaymentGateway
    {
        return match ($provider) {
            'stripe' => $this->stripe, 'paypal' => $this->paypal, default => abort(404)
        };
    }

    public function checkout($user, string $invoiceId, array $input): array
    {
        $invoice = $this->invoices->find($user, $invoiceId);
        $data = Validator::make($input, ['provider' => ['required', Rule::in(['stripe', 'paypal'])], 'idempotency_key' => 'required|string|min:8|max:100'])->validate();
        $gateway = $this->gateway($data['provider']);
        abort_unless($gateway->configured(), 503, 'Configure the selected payment provider before creating a checkout.');
        abort_unless(str_starts_with(config('app.url'), 'https://') || app()->environment(['local', 'testing']), 422, 'A public HTTPS application URL is required for online payments.');
        $id = substr(hash('sha256', $invoiceId.':'.$data['provider'].':'.$data['idempotency_key']), 0, 32);
        $checkout = $this->store->transaction(function () use ($invoiceId, $user, $data, $id, $gateway) {
            if ($old = $this->store->get('checkouts', $id)) {
                return $old;
            }
            $invoice = $this->store->get('invoices', $invoiceId);
            $amount = (int) $invoice['total_minor'] - (int) $invoice['paid_minor'] - (int) ($invoice['credited_minor'] ?? '0');
            abort_unless($invoice['status'] !== 'draft' && $amount > 0, 409, 'Only an issued invoice with an outstanding balance can be paid.');
            $gateway->amount((string) $amount, $invoice['currency']);

            return $this->store->create('checkouts', ['invoice_id' => $invoiceId, 'provider' => $data['provider'], 'amount_minor' => (string) $amount, 'currency' => $invoice['currency'], 'status' => 'creating', 'requested_by' => $user->id, 'owner_id' => $invoice['owner_id'], 'client_ids' => $invoice['client_ids'], 'team_ids' => $invoice['team_ids'] ?? []], $id);
        });
        if (isset($checkout['provider_id'])) {
            return $checkout;
        }
        // Network calls are outside all retryable record-store transactions.
        $remote = $gateway->createCheckout($checkout, $invoice);

        return $this->store->transaction(function () use ($id, $remote) {
            $current = $this->store->get('checkouts', $id);

            return isset($current['provider_id']) ? $current : $this->store->put('checkouts', $id, array_replace($current, $remote), $current['version']);
        });
    }

    public function reconcile($user, string $id): array
    {
        $checkout = $this->store->get('checkouts', $id);
        abort_unless($checkout !== null, 404);
        $this->invoices->find($user, $checkout['invoice_id']);

        return $this->reconcilePendingCheckout($id);
    }

    /** Internal job entry point. Only stored provider references are used. */
    public function reconcilePendingCheckout(string $id): array
    {
        $checkout = $this->store->get('checkouts', $id);
        abort_unless($checkout !== null, 404);
        if (in_array($checkout['status'], ['paid', 'review_required', 'expired'], true)) {
            return $checkout;
        }
        abort_unless(isset($checkout['provider_id']), 409, 'Checkout creation has not completed. Retry checkout creation with the original key.');
        $remote = $this->gateway($checkout['provider'])->status($checkout['provider_id']);
        if ($checkout['provider'] === 'stripe' && ($remote['payment_status'] ?? '') === 'paid') {
            $this->stripeCapture($remote);
        } elseif ($checkout['provider'] === 'paypal') {
            if (($remote['status'] ?? '') === 'APPROVED') {
                foreach ($remote['purchase_units'] ?? [] as $unit) {
                    abort_unless(($unit['payee']['merchant_id'] ?? '') === config('services.paypal.merchant_id'), 422, 'PayPal merchant does not match.');
                }
                $this->paypal->capture($checkout['provider_id'], 'capture-'.$id);
                // Capture responses may be minimal; retrieve authoritative order details for merchant and capture verification.
                $remote = $this->paypal->status($checkout['provider_id']);
            }
            foreach ($remote['purchase_units'] ?? [] as $unit) {
                abort_unless(($unit['payee']['merchant_id'] ?? '') === config('services.paypal.merchant_id'), 422, 'PayPal merchant does not match.');
                foreach ($unit['payments']['captures'] ?? [] as $capture) {
                    if (($capture['status'] ?? '') === 'COMPLETED') {
                        $this->capture($checkout, $capture['id'], $this->paypal->minor($capture['amount']['value'], $capture['amount']['currency_code']), $capture['amount']['currency_code']);
                    }
                }
            }
        }
        if ($checkout['provider'] === 'stripe' && ($remote['status'] ?? '') === 'expired') {
            $this->store->transaction(function () use ($id) {
                $current = $this->store->get('checkouts', $id);
                if ($current['status'] === 'pending') {
                    $this->store->put('checkouts', $id, array_replace($current, ['status' => 'expired']), $current['version']);
                }
            });
        }

        return $this->store->get('checkouts', $id);
    }

    public function queuePendingChecks(): int
    {
        $count = 0;
        foreach ($this->store->query('checkouts', ['status' => 'pending'], 100) as $checkout) {
            if (isset($checkout['provider_id']) && $this->gateway($checkout['provider'])->configured()) {
                $this->outbox->enqueue('payment.reconcile', ['checkout_id' => $checkout['id']], $checkout['id'].':'.now()->format('Y-m-d-H'));
                $count++;
            }
        }

        return $count;
    }

    public function webhook(string $provider, string $body, array $headers): array
    {
        abort_if(strlen($body) > 1024 * 1024, 413, 'Webhook payload is too large.');
        $event = $this->gateway($provider)->verifyWebhook($body, $headers);
        $receiptId = hash('sha256', $provider.':'.$event['id']);
        $receipt = $this->store->transaction(function () use ($receiptId, $event, $provider, $body) {
            return $this->store->get('webhook_receipts', $receiptId) ?? $this->store->create('webhook_receipts', ['provider' => $provider, 'event_id' => $event['id'], 'event_type' => $event['type'] ?? $event['event_type'], 'body_hash' => hash('sha256', $body), 'status' => 'verified', 'verified_at' => now()->toIso8601String()], $receiptId);
        });
        abort_unless(hash_equals($receipt['body_hash'], hash('sha256', $body)), 400, 'Event ID has a different payload.');
        if ($receipt['status'] === 'processed') {
            return ['received' => true, 'duplicate' => true];
        }
        $kind = $event['type'] ?? $event['event_type'];
        if ($provider === 'stripe' && in_array($kind, ['checkout.session.completed', 'checkout.session.async_payment_succeeded'], true)) {
            $object = $event['data']['object'];
            if (($object['payment_status'] ?? '') === 'paid') {
                $this->stripeCapture($object);
            }
        } elseif ($provider === 'stripe' && in_array($kind, ['refund.created', 'refund.updated'], true)) {
            $refund = $event['data']['object'];
            if (($refund['status'] ?? '') === 'succeeded') {
                $this->providerRefund('stripe', $refund['payment_intent'] ?? '', $this->stripe->minor((string) $refund['amount'], strtoupper($refund['currency'])), strtoupper($refund['currency']), $refund['id']);
            }
        } elseif ($provider === 'paypal' && $kind === 'CHECKOUT.ORDER.APPROVED') {
            $checkout = $this->checkoutByProvider('paypal', $event['resource']['id']);
            $this->reconcilePendingCheckout($checkout['id']);
        } elseif ($provider === 'paypal' && $kind === 'PAYMENT.CAPTURE.COMPLETED') {
            $capture = $event['resource'];
            $orderId = $capture['supplementary_data']['related_ids']['order_id'] ?? '';
            $checkout = $this->checkoutByProvider('paypal', $orderId);
            // Retrieve the order with this merchant's credential to confirm ownership and captured resource.
            $order = $this->paypal->status($orderId);
            $matched = false;
            foreach ($order['purchase_units'] ?? [] as $unit) {
                abort_unless(($unit['payee']['merchant_id'] ?? '') === config('services.paypal.merchant_id'), 422, 'PayPal merchant does not match.');
                foreach ($unit['payments']['captures'] ?? [] as $known) {
                    if ($known['id'] === $capture['id'] && ($known['status'] ?? '') === 'COMPLETED') {
                        $matched = true;
                    }
                }
            }
            abort_unless($matched, 422, 'PayPal capture is not part of this order.');
            $this->capture($checkout, $capture['id'], $this->paypal->minor($capture['amount']['value'], $capture['amount']['currency_code']), $capture['amount']['currency_code']);
        } elseif ($provider === 'paypal' && $kind === 'PAYMENT.CAPTURE.REFUNDED') {
            $refund = $event['resource'];
            $captureId = $refund['supplementary_data']['related_ids']['capture_id'] ?? '';
            if (! $captureId) {
                foreach ($refund['links'] ?? [] as $link) {
                    if (($link['rel'] ?? '') === 'up' && preg_match('~/v2/payments/captures/([A-Za-z0-9]+)$~', $link['href'] ?? '', $m)) {
                        $captureId = $m[1];
                    }
                }
            }
            $this->providerRefund('paypal', $captureId, $this->paypal->minor($refund['amount']['value'], $refund['amount']['currency_code']), $refund['amount']['currency_code'], $refund['id']);
        }
        $this->store->transaction(function () use ($receiptId) {
            $record = $this->store->get('webhook_receipts', $receiptId);
            $this->store->put('webhook_receipts', $receiptId, array_replace($record, ['status' => 'processed', 'processed_at' => now()->toIso8601String()]), $record['version']);
            $this->audit->log(null, 'payment.webhook_processed', 'webhook_receipts', $receiptId);
        });

        return ['received' => true];
    }

    private function stripeCapture(array $object): void
    {
        $checkout = $this->checkoutByProvider('stripe', $object['id']);
        abort_unless(($object['client_reference_id'] ?? '') === $checkout['id'] && ($object['metadata']['invoice_id'] ?? '') === $checkout['invoice_id'] && ! empty($object['payment_intent']), 422, 'Stripe checkout metadata does not match.');
        $this->capture($checkout, $object['payment_intent'], $this->stripe->minor((string) $object['amount_total'], strtoupper($object['currency'])), strtoupper($object['currency']));
    }

    private function checkoutByProvider(string $provider, string $id): array
    {
        $checkout = $this->store->query('checkouts', ['provider' => $provider, 'provider_id' => $id], 1)[0] ?? null;
        abort_unless($checkout !== null, 409, 'Checkout is not recorded yet; provider should retry delivery.');

        return $checkout;
    }

    private function capture(array $checkout, string $captureId, string $amount, string $currency): void
    {
        abort_unless($amount === $checkout['amount_minor'] && $currency === $checkout['currency'], 422, 'Provider amount or currency does not match the checkout.');
        $this->store->transaction(function () use ($checkout, $captureId, $amount, $currency) {
            $current = $this->store->get('checkouts', $checkout['id']);
            if (($current['capture_id'] ?? null) === $captureId && in_array($current['status'], ['paid', 'review_required'], true)) {
                return;
            }
            $invoice = $this->store->get('invoices', $checkout['invoice_id']);
            $outstanding = (int) $invoice['total_minor'] - (int) $invoice['paid_minor'] - (int) ($invoice['credited_minor'] ?? '0');
            if ((int) $amount > $outstanding) {
                $id = hash('sha256', $checkout['provider'].':'.$captureId);
                if (! $this->store->get('unapplied_payments', $id)) {
                    $this->store->create('unapplied_payments', ['checkout_id' => $checkout['id'], 'invoice_id' => $invoice['id'], 'provider' => $checkout['provider'], 'capture_id' => $captureId, 'amount_minor' => $amount, 'currency' => $currency, 'reason' => 'Invoice balance changed before provider capture. Reconcile and refund manually.'], $id);
                }
                $status = 'review_required';
                $this->outbox->enqueue('payment.review_required', ['checkout_id' => $checkout['id']], 'payment-review-'.$id);
            } else {
                $this->invoices->allocate($checkout['invoice_id'], $amount, $checkout['provider'], $captureId, $captureId, null, $currency);
                $status = 'paid';
            }
            $this->store->put('checkouts', $checkout['id'], array_replace($current, ['capture_id' => $captureId, 'status' => $status]), $current['version']);
        });
    }

    public function refund($user, string $paymentId, array $input): array
    {
        $payment = $this->store->get('payments', $paymentId);
        abort_unless($payment !== null, 404);
        $this->access->authorize($user, 'invoices.issue', $this->store->get('invoices', $payment['invoice_id']));
        $data = Validator::make($input, ['amount_minor' => 'required', 'reference' => 'required|string|max:500', 'idempotency_key' => 'required|string|min:8|max:100'])->validate();
        $amount = (string) Money::minor($data['amount_minor'], allowZero: false);
        if (in_array($payment['method'], ['bank_transfer', 'cash'], true)) {
            return $this->invoices->refundRecord($paymentId, $amount, $data['reference'], 'manual:'.$data['idempotency_key'], $user->id);
        }
        $this->gateway($payment['method'])->amount($amount, $payment['currency']);
        $requestId = substr(hash('sha256', $paymentId.':'.$data['idempotency_key']), 0, 32);
        $request = $this->store->transaction(function () use ($requestId, $paymentId, $amount, $user) {
            if ($old = $this->store->get('refund_requests', $requestId)) {
                abort_unless($old['amount_minor'] === $amount, 409, 'Refund request key used for another amount.');

                return $old;
            }
            $current = $this->store->get('payments', $paymentId);
            abort_if((int) $amount > (int) $current['amount_minor'] - (int) $current['refunded_minor'], 409, 'Refund exceeds this payment.');

            return $this->store->create('refund_requests', ['payment_id' => $paymentId, 'amount_minor' => $amount, 'actor_id' => $user->id, 'status' => 'pending'], $requestId);
        });
        if ($request['status'] === 'completed') {
            return $request;
        }
        $remote = $this->gateway($payment['method'])->refund($payment['reference'], $amount, $payment['currency'], $requestId);
        if (in_array($remote['status'] ?? '', ['succeeded', 'COMPLETED'], true)) {
            $this->invoices->refundRecord($paymentId, $amount, $remote['id'], 'provider:'.$remote['id'], $user->id);
            $request = $this->store->get('refund_requests', $requestId);

            return $this->store->put('refund_requests', $requestId, array_replace($request, ['status' => 'completed', 'provider_refund_id' => $remote['id']]), $request['version']);
        }

        return array_merge($request, ['provider_status' => $remote['status'] ?? 'pending']);
    }

    private function providerRefund(string $provider, string $captureId, string $amount, string $currency, string $refundId): void
    {
        $payment = $this->store->query('payments', ['method' => $provider, 'reference' => $captureId], 1)[0] ?? null;
        abort_unless($payment !== null, 409, 'Payment capture has not been recorded yet.');
        abort_unless($payment['currency'] === $currency, 422, 'Refund currency does not match.');
        $this->invoices->refundRecord($payment['id'], $amount, $refundId, 'provider:'.$refundId, null);
    }
}
