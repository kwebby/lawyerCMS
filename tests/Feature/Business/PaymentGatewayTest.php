<?php

// Author: ramanpal singh | URL: https://kwebby.com

namespace Tests\Feature\Business;

use App\Domain\Finance\InvoiceService;
use App\Domain\Finance\PaymentService;
use App\Domain\Finance\PaypalGateway;
use App\Domain\Finance\StripeGateway;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class PaymentGatewayTest extends BusinessTestCase
{
    private function setupStripe(): array
    {
        config(['services.stripe.secret' => 'sk_test_example', 'services.stripe.webhook_secret' => 'whsec_test_example', 'app.url' => 'https://crm.example.test']);
        $service = app(InvoiceService::class);

        return $service->issue($this->owner, $service->save($this->owner, $this->invoiceInput())['id']);
    }

    private function signed(string $body): array
    {
        $t = time();

        return ['stripe-signature' => 't='.$t.',v1='.hash_hmac('sha256', $t.'.'.$body, 'whsec_test_example')];
    }

    public function test_checkout_uses_server_amount_and_signed_duplicate_webhook_allocates_once(): void
    {
        $invoice = $this->setupStripe();
        Http::fake(['api.stripe.com/v1/checkout/sessions' => Http::response(['id' => 'cs_test_123', 'url' => 'https://checkout.stripe.com/test_123'])]);
        $payments = app(PaymentService::class);
        $checkout = $payments->checkout($this->owner, $invoice['id'], ['provider' => 'stripe', 'idempotency_key' => 'checkout-one', 'amount_minor' => '1']);
        Http::assertSent(fn ($r) => $r['line_items'][0]['price_data']['unit_amount'] === '22000');
        $event = ['id' => 'evt_test_1', 'type' => 'checkout.session.completed', 'data' => ['object' => ['id' => 'cs_test_123', 'client_reference_id' => $checkout['id'], 'metadata' => ['invoice_id' => $invoice['id']], 'payment_status' => 'paid', 'payment_intent' => 'pi_test_123', 'amount_total' => 22000, 'currency' => 'usd']]];
        $body = json_encode($event);
        $payments->webhook('stripe', $body, $this->signed($body));
        $result = $payments->webhook('stripe', $body, $this->signed($body));
        $this->assertTrue($result['duplicate']);
        $this->assertSame('22000', $this->store->get('invoices', $invoice['id'])['paid_minor']);
        $this->assertCount(1, $this->store->query('payments'));
        $this->assertSame('processed', $this->store->query('webhook_receipts')[0]['status']);
    }

    public function test_forged_expired_or_wrong_amount_webhook_cannot_mark_invoice_paid(): void
    {
        $invoice = $this->setupStripe();
        $checkout = $this->store->create('checkouts', ['provider' => 'stripe', 'provider_id' => 'cs_test', 'invoice_id' => $invoice['id'], 'amount_minor' => '22000', 'currency' => 'USD', 'status' => 'pending']);
        $body = json_encode(['id' => 'evt_bad_amount', 'type' => 'checkout.session.completed', 'data' => ['object' => ['id' => 'cs_test', 'client_reference_id' => $checkout['id'], 'metadata' => ['invoice_id' => $invoice['id']], 'payment_status' => 'paid', 'payment_intent' => 'pi_test', 'amount_total' => 1, 'currency' => 'usd']]]);
        $this->call('POST', '/api/v1/payments/webhooks/stripe', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => 't='.time().',v1=forged'], $body)->assertStatus(400);
        $old = time() - 301;
        $oldSignature = 't='.$old.',v1='.hash_hmac('sha256', $old.'.'.$body, 'whsec_test_example');
        $this->call('POST', '/api/v1/payments/webhooks/stripe', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => $oldSignature], $body)->assertStatus(400);
        $this->call('POST', '/api/v1/payments/webhooks/stripe', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => $this->signed($body)['stripe-signature']], $body)->assertUnprocessable();
        $this->assertSame('0', $this->store->get('invoices', $invoice['id'])['paid_minor']);
        $this->assertCount(0, $this->store->query('payments'));
    }

    public function test_balance_change_creates_reconciliation_exception_without_losing_provider_capture(): void
    {
        $invoice = $this->setupStripe();
        $checkout = $this->store->create('checkouts', ['provider' => 'stripe', 'provider_id' => 'cs_test', 'invoice_id' => $invoice['id'], 'amount_minor' => '22000', 'currency' => 'USD', 'status' => 'pending']);
        app(InvoiceService::class)->payment($this->owner, $invoice['id'], ['amount_minor' => '22000', 'method' => 'cash', 'reference' => 'Cash receipt', 'idempotency_key' => 'manual-payment']);
        $body = json_encode(['id' => 'evt_overpaid', 'type' => 'checkout.session.completed', 'data' => ['object' => ['id' => 'cs_test', 'client_reference_id' => $checkout['id'], 'metadata' => ['invoice_id' => $invoice['id']], 'payment_status' => 'paid', 'payment_intent' => 'pi_test', 'amount_total' => 22000, 'currency' => 'usd']]]);
        app(PaymentService::class)->webhook('stripe', $body, $this->signed($body));
        $this->assertSame('review_required', $this->store->get('checkouts', $checkout['id'])['status']);
        $this->assertCount(1, $this->store->query('unapplied_payments'));
        $this->assertSame('22000', $this->store->get('invoices', $invoice['id'])['paid_minor']);
    }

    public function test_paypal_approved_order_is_captured_then_verified_from_authoritative_order(): void
    {
        config(['services.paypal.client_id' => 'client', 'services.paypal.secret' => 'secret', 'services.paypal.webhook_id' => 'webhook-id', 'services.paypal.merchant_id' => 'merchant-id', 'services.paypal.mode' => 'sandbox']);
        $invoice = app(InvoiceService::class)->issue($this->owner, app(InvoiceService::class)->save($this->owner, $this->invoiceInput())['id']);
        $checkout = $this->store->create('checkouts', ['provider' => 'paypal', 'provider_id' => 'ORDER123', 'invoice_id' => $invoice['id'], 'amount_minor' => '22000', 'currency' => 'USD', 'status' => 'pending']);
        $unit = ['payee' => ['merchant_id' => 'merchant-id']];
        $capture = ['id' => 'CAPTURE123', 'status' => 'COMPLETED', 'amount' => ['currency_code' => 'USD', 'value' => '220.00']];
        Http::fake([
            'api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response(['access_token' => 'access']),
            'api-m.sandbox.paypal.com/v2/checkout/orders/ORDER123' => Http::sequence()->push(['id' => 'ORDER123', 'status' => 'APPROVED', 'purchase_units' => [$unit]])->push(['id' => 'ORDER123', 'status' => 'COMPLETED', 'purchase_units' => [array_merge($unit, ['payments' => ['captures' => [$capture]]])]]),
            'api-m.sandbox.paypal.com/v2/checkout/orders/ORDER123/capture' => Http::response(['id' => 'ORDER123', 'status' => 'COMPLETED']),
        ]);
        $result = app(PaymentService::class)->reconcile($this->owner, $checkout['id']);
        $this->assertSame('paid', $result['status']);
        $this->assertSame('22000', $this->store->get('invoices', $invoice['id'])['paid_minor']);
        $this->assertCount(1, $this->store->query('payments'));
    }

    public function test_paypal_verifies_signature_at_fixed_endpoint_and_converts_decimal_exactly(): void
    {
        config(['services.paypal.client_id' => 'client', 'services.paypal.secret' => 'secret', 'services.paypal.webhook_id' => 'webhook-id', 'services.paypal.merchant_id' => 'merchant-id', 'services.paypal.mode' => 'sandbox']);
        Http::fake(['api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response(['access_token' => 'access']), 'api-m.sandbox.paypal.com/v1/notifications/verify-webhook-signature' => Http::response(['verification_status' => 'SUCCESS'])]);
        $gateway = app(PaypalGateway::class);
        $this->assertSame('100.01', $gateway->decimal('10001', 'USD'));
        $this->assertSame('10001', $gateway->minor('100.01', 'USD'));
        $this->assertSame('100', $gateway->minor('100', 'JPY'));
        $event = ['id' => 'EVENT1', 'event_type' => 'OTHER.EVENT', 'resource' => []];
        $this->assertSame($event, $gateway->verifyWebhook(json_encode($event), ['paypal-auth-algo' => 'SHA256withRSA', 'paypal-cert-url' => 'http://127.0.0.1/never-fetch-this', 'paypal-transmission-id' => 'transmission', 'paypal-transmission-sig' => 'signature', 'paypal-transmission-time' => now()->toISOString()]));
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '127.0.0.1'));
    }

    private function setupPaypal(): void
    {
        config(['services.paypal.client_id' => 'client', 'services.paypal.secret' => 'secret', 'services.paypal.webhook_id' => 'webhook-id', 'services.paypal.merchant_id' => 'merchant-id', 'services.paypal.mode' => 'sandbox', 'app.url' => 'https://crm.example.test']);
    }

    private function assertRefused(callable $call, string $field): void
    {
        try {
            $call();
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($field, $e->errors());

            return;
        }
        $this->fail('Expected the provider adapter to refuse this amount or currency.');
    }

    public function test_paypal_converts_iso_minor_units_and_refuses_amounts_it_cannot_represent(): void
    {
        $gateway = app(PaypalGateway::class);
        foreach ([['10001', 'USD', '100.01'], ['1000000', 'HUF', '10000'], ['250000', 'TWD', '2500'], ['10000', 'JPY', '10000']] as [$minor, $currency, $value]) {
            $this->assertSame($value, $gateway->decimal($minor, $currency));
            $this->assertSame($minor, $gateway->minor($value, $currency));
        }
        $this->assertSame('1000000', $gateway->minor('10000.00', 'HUF'));
        foreach ([['1000050', 'HUF', 'amount_minor'], ['250001', 'TWD', 'amount_minor'], ['1000000', 'RSD', 'currency'], ['12345', 'KWD', 'currency'], ['12340', 'BHD', 'currency'], ['100', 'XYZ', 'currency']] as [$minor, $currency, $field]) {
            $this->assertRefused(fn () => $gateway->decimal($minor, $currency), $field);
        }
        try {
            $gateway->minor('10000.50', 'HUF');
            $this->fail('PayPal never reports fractional forints.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
    }

    public function test_stripe_amounts_follow_its_currency_rules_or_are_refused(): void
    {
        $gateway = app(StripeGateway::class);
        foreach ([['22000', 'USD', '22000'], ['1000000', 'HUF', '1000000'], ['250000', 'TWD', '250000'], ['500', 'JPY', '500'], ['1000000', 'RSD', '1000000'], ['12340', 'KWD', '12340'], ['1000', 'BHD', '1000'], ['500000', 'MGA', '5000']] as [$minor, $currency, $amount]) {
            $this->assertSame($amount, $gateway->amount($minor, $currency));
            $this->assertSame($minor, $gateway->minor($amount, $currency));
        }
        foreach ([['1000050', 'HUF', 'amount_minor'], ['250001', 'TWD', 'amount_minor'], ['12345', 'KWD', 'amount_minor'], ['1005', 'BHD', 'amount_minor'], ['500050', 'MGA', 'amount_minor'], ['500', 'ISK', 'currency'], ['500', 'UGX', 'currency'], ['2125', 'IQD', 'currency'], ['100', 'XYZ', 'currency']] as [$minor, $currency, $field]) {
            $this->assertRefused(fn () => $gateway->amount($minor, $currency), $field);
        }
    }

    public function test_paypal_huf_checkout_requests_whole_forints_and_capture_settles_the_iso_amount(): void
    {
        $this->setupPaypal();
        $service = app(InvoiceService::class);
        $invoice = $service->issue($this->owner, $service->save($this->owner, $this->invoiceInput(['currency' => 'HUF', 'items' => [['description' => 'Advice', 'quantity' => '1', 'unit_minor' => '1000000', 'tax_bps' => 0]]]))['id']);
        $unit = ['payee' => ['merchant_id' => 'merchant-id']];
        $capture = ['id' => 'CAPTUREHUF', 'status' => 'COMPLETED', 'amount' => ['currency_code' => 'HUF', 'value' => '10000']];
        Http::fake([
            'api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response(['access_token' => 'access']),
            'api-m.sandbox.paypal.com/v2/checkout/orders' => Http::response(['id' => 'ORDERHUF', 'links' => [['rel' => 'payer-action', 'href' => 'https://www.sandbox.paypal.com/checkoutnow?token=ORDERHUF']]]),
            'api-m.sandbox.paypal.com/v2/checkout/orders/ORDERHUF' => Http::sequence()->push(['id' => 'ORDERHUF', 'status' => 'APPROVED', 'purchase_units' => [$unit]])->push(['id' => 'ORDERHUF', 'status' => 'COMPLETED', 'purchase_units' => [array_merge($unit, ['payments' => ['captures' => [$capture]]])]]),
            'api-m.sandbox.paypal.com/v2/checkout/orders/ORDERHUF/capture' => Http::response(['id' => 'ORDERHUF', 'status' => 'COMPLETED']),
        ]);
        $payments = app(PaymentService::class);
        $checkout = $payments->checkout($this->owner, $invoice['id'], ['provider' => 'paypal', 'idempotency_key' => 'huf-checkout']);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/v2/checkout/orders') && $r['purchase_units'][0]['amount'] === ['currency_code' => 'HUF', 'value' => '10000']);
        $this->assertSame('paid', $payments->reconcile($this->owner, $checkout['id'])['status']);
        $this->assertSame('1000000', $this->store->get('invoices', $invoice['id'])['paid_minor']);
    }

    public function test_fractional_huf_balance_is_refused_before_a_checkout_is_recorded(): void
    {
        $this->setupPaypal();
        $service = app(InvoiceService::class);
        $invoice = $service->issue($this->owner, $service->save($this->owner, $this->invoiceInput(['currency' => 'HUF', 'items' => [['description' => 'Advice', 'quantity' => '1', 'unit_minor' => '1000050', 'tax_bps' => 0]]]))['id']);
        $this->postJson('/api/v1/invoices/'.$invoice['id'].'/checkout', ['provider' => 'paypal', 'idempotency_key' => 'huf-fraction'])->assertUnprocessable()->assertJsonValidationErrors('amount_minor');
        $this->assertCount(0, $this->store->query('checkouts'));
        Http::assertNothingSent();
    }

    public function test_stripe_three_decimal_checkout_and_webhook_use_iso_minor_units(): void
    {
        config(['services.stripe.secret' => 'sk_test_example', 'services.stripe.webhook_secret' => 'whsec_test_example', 'app.url' => 'https://crm.example.test']);
        $service = app(InvoiceService::class);
        $invoice = $service->issue($this->owner, $service->save($this->owner, $this->invoiceInput(['currency' => 'KWD', 'items' => [['description' => 'Advice', 'quantity' => '1', 'unit_minor' => '12340', 'tax_bps' => 0]]]))['id']);
        Http::fake(['api.stripe.com/v1/checkout/sessions' => Http::response(['id' => 'cs_kwd', 'url' => 'https://checkout.stripe.com/kwd'])]);
        $payments = app(PaymentService::class);
        $checkout = $payments->checkout($this->owner, $invoice['id'], ['provider' => 'stripe', 'idempotency_key' => 'kwd-checkout']);
        Http::assertSent(fn ($r) => $r['line_items'][0]['price_data'] === ['currency' => 'kwd', 'unit_amount' => '12340', 'product_data' => ['name' => 'Invoice '.$invoice['number']]]);
        $body = json_encode(['id' => 'evt_kwd', 'type' => 'checkout.session.completed', 'data' => ['object' => ['id' => 'cs_kwd', 'client_reference_id' => $checkout['id'], 'metadata' => ['invoice_id' => $invoice['id']], 'payment_status' => 'paid', 'payment_intent' => 'pi_kwd', 'amount_total' => 12340, 'currency' => 'kwd']]]);
        $payments->webhook('stripe', $body, $this->signed($body));
        $this->assertSame('12340', $this->store->get('invoices', $invoice['id'])['paid_minor']);
    }
}
