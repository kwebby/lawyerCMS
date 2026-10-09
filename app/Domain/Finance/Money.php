<?php

// Author: ramanpal singh | URL: https://kwebby.com

namespace App\Domain\Finance;

use Illuminate\Validation\ValidationException;

/** Integer minor units, three-decimal quantities, basis-point tax; round half up per line. */
final class Money
{
    public const MAX_MINOR = 999999999999;

    public static function minor(mixed $value, string $field = 'amount_minor', bool $allowZero = true): int
    {
        if (! is_string($value) && ! is_int($value)) {
            throw ValidationException::withMessages([$field => 'Use an integer string in currency minor units.']);
        }
        $value = (string) $value;
        if (! preg_match('/^(0|[1-9][0-9]{0,11})$/D', $value) || (! $allowZero && $value === '0')) {
            throw ValidationException::withMessages([$field => 'Use a non-negative integer string in currency minor units (maximum 999999999999).']);
        }

        return (int) $value;
    }

    public static function quantity(mixed $value): int
    {
        $value = (string) $value;
        if (! preg_match('/^(0|[1-9][0-9]{0,3})(?:\.([0-9]{1,3}))?$/D', $value, $m)) {
            throw ValidationException::withMessages(['items' => 'Quantities must be between 0.001 and 9999.999 with at most three decimals.']);
        }
        $quantity = (int) $m[1] * 1000 + (int) str_pad($m[2] ?? '', 3, '0');
        if ($quantity === 0) {
            throw ValidationException::withMessages(['items' => 'Quantity must be positive.']);
        }

        return $quantity;
    }

    public static function calculate(array $items, mixed $discount = '0'): array
    {
        $lines = [];
        $subtotal = 0;
        foreach ($items as $item) {
            $unit = self::minor($item['unit_minor'], 'items.unit_minor');
            $quantity = self::quantity($item['quantity'] ?? '1');
            // Bound product to signed 64-bit before multiplication.
            if ($unit > intdiv(PHP_INT_MAX - 500, $quantity)) {
                throw ValidationException::withMessages(['items' => 'Line amount exceeds the supported range.']);
            }
            $amount = intdiv($unit * $quantity + 500, 1000);
            if ($amount > self::MAX_MINOR || $subtotal + $amount > self::MAX_MINOR) {
                throw ValidationException::withMessages(['items' => 'Invoice amount exceeds the supported range.']);
            }
            $subtotal += $amount;
            $lines[] = ['description' => $item['description'], 'quantity' => (string) ($item['quantity'] ?? '1'), 'unit_minor' => (string) $unit, 'tax_bps' => (int) ($item['tax_bps'] ?? 0), 'subtotal_minor' => (string) $amount];
        }
        $discount = self::minor($discount, 'discount_minor');
        if ($discount > $subtotal) {
            throw ValidationException::withMessages(['discount_minor' => 'Discount cannot exceed the pre-tax subtotal.']);
        }
        // Allocate discount proportionately using quotient/remainder without overflowing products.
        $remainingDiscount = $discount;
        $remainingSubtotal = $subtotal;
        $taxTotal = 0;
        foreach ($lines as &$line) {
            $amount = (int) $line['subtotal_minor'];
            $allocated = $remainingSubtotal === $amount ? $remainingDiscount : self::proportion($remainingDiscount, $amount, max(1, $remainingSubtotal));
            $taxable = $amount - $allocated;
            $tax = intdiv($taxable * $line['tax_bps'] + 5000, 10000);
            $line['discount_minor'] = (string) $allocated;
            $line['tax_minor'] = (string) $tax;
            $line['total_minor'] = (string) ($taxable + $tax);
            $remainingDiscount -= $allocated;
            $remainingSubtotal -= $amount;
            $taxTotal += $tax;
        }
        $total = $subtotal - $discount + $taxTotal;
        if ($total > self::MAX_MINOR) {
            throw ValidationException::withMessages(['items' => 'Invoice total exceeds the supported range.']);
        }

        return ['items' => $lines, 'subtotal_minor' => (string) $subtotal, 'discount_minor' => (string) $discount, 'tax_minor' => (string) $taxTotal, 'total_minor' => (string) $total];
    }

    private static function proportion(int $a, int $b, int $divisor): int
    {
        // BCMath is not a baseline requirement. Decimal long division avoids floating point.
        $result = 0;
        $remainder = 0;
        foreach (str_split((string) $b) as $digit) {
            $remainder = $remainder * 10 + $a * (int) $digit;
            $result = $result * 10 + intdiv($remainder, $divisor);
            $remainder %= $divisor;
        }

        return $result;
    }
}
