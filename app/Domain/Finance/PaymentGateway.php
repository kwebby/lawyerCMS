<?php

// Author: ramanpal singh | URL: https://kwebby.com

namespace App\Domain\Finance;

interface PaymentGateway
{
    public function configured(): bool;

    public function capabilities(): array;

    public function createCheckout(array $checkout, array $invoice): array;

    public function status(string $providerId): array;

    public function verifyWebhook(string $body, array $headers): array;

    public function refund(string $captureId, string $amountMinor, string $currency, string $idempotencyKey): array;
}
