<?php

// Author: ramanpal singh | URL: https://kwebby.com

namespace Tests\Unit\Business;

use App\Domain\Finance\Currency;
use App\Domain\Finance\FinancialDocuments;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class CurrencyTest extends TestCase
{
    public function test_server_and_browser_read_the_same_iso_4217_file(): void
    {
        $file = json_decode(file_get_contents(resource_path('data/iso4217.json')), true, 8, JSON_THROW_ON_ERROR);
        $this->assertSame($file, Currency::table());
        foreach ($file['minor_units'] as $code => $exponent) {
            $this->assertMatchesRegularExpression('/^[A-Z]{3}$/D', $code);
            $this->assertContains($exponent, [0, 2, 3, 4]);
            $this->assertSame($exponent, Currency::exponent($code));
        }
        $this->assertSame([2, 2, 0, 0, 3, 3, 3], array_map(Currency::exponent(...), ['HUF', 'TWD', 'JPY', 'ISK', 'KWD', 'BHD', 'IQD']));
        $api = file_get_contents(resource_path('js/lib/api.ts'));
        $this->assertStringContainsString('import iso4217 from "../../data/iso4217.json";', $api);
        $this->assertStringNotContainsString('maximumFractionDigits', $api);
    }

    public function test_documents_format_with_the_shared_table_and_never_guess_unknown_codes(): void
    {
        $money = fn (string $minor, string $currency) => (fn () => $this->money($minor, $currency))->call(app(FinancialDocuments::class));
        $this->assertSame('RSD 10000.00', $money('1000000', 'RSD'));
        $this->assertSame('IQD 2.125', $money('2125', 'IQD'));
        $this->assertSame('CLF 123.4567', $money('1234567', 'CLF'));
        $this->assertSame('UYI 5', $money('5', 'UYI'));
        $this->assertSame('KWD 0.005', $money('5', 'KWD'));
        $this->assertSame('HRK 12.34', $money('1234', 'HRK'));
        $this->assertSame('XYZ 1234 (minor units)', $money('1234', 'XYZ'));
        $this->expectException(ValidationException::class);
        Currency::exponent('HRK');
    }
}
