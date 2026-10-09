<?php

// Author: ramanpal singh | URL: https://kwebby.com

namespace App\Domain\Finance;

use Illuminate\Validation\ValidationException;

/** ISO 4217 minor units from resources/data/iso4217.json, the same file the browser imports for entry and display. */
final class Currency
{
    private static ?array $table = null;

    public static function table(): array
    {
        return self::$table ??= json_decode((string) file_get_contents(resource_path('data/iso4217.json')), true, 8, JSON_THROW_ON_ERROR);
    }

    /** Validation rule: an active ISO 4217 code with defined minor units. */
    public static function rule(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail): void {
            if (! is_string($value) || ! isset(self::table()['minor_units'][$value])) {
                $fail('Use a supported ISO 4217 currency code, such as USD or EUR.');
            }
        };
    }

    public static function exponent(string $currency): int
    {
        $exponent = self::table()['minor_units'][$currency] ?? null;
        if ($exponent === null) {
            throw ValidationException::withMessages(['currency' => 'Use a supported ISO 4217 currency code, such as USD or EUR.']);
        }

        return $exponent;
    }

    /** Exact decimal string for non-negative integer minor units, e.g. ("12345", 2) → "123.45". */
    public static function decimal(string $minor, int $exponent): string
    {
        $digits = str_pad($minor, $exponent + 1, '0', STR_PAD_LEFT);

        return $exponent ? substr($digits, 0, -$exponent).'.'.substr($digits, -$exponent) : $digits;
    }

    /** Display form for documents. Withdrawn codes still format existing records; unknown codes show raw minor units rather than a guessed scale. */
    public static function format(string $minor, string $currency): string
    {
        $exponent = self::table()['minor_units'][$currency] ?? self::table()['withdrawn_minor_units'][$currency] ?? null;

        return $exponent === null ? $currency.' '.$minor.' (minor units)' : $currency.' '.self::decimal($minor, $exponent);
    }
}
