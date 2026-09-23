<?php

namespace Tests\Unit;

use App\Support\SpCredits;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class SpCreditsTest extends TestCase
{
    public function test_exact_credit_decimal_conversion(): void
    {
        $this->assertSame('50', SpCredits::decimal(5_000_000, 100_000));
        $this->assertSame('40', SpCredits::decimal(4_000_000, 100_000));
        $this->assertSame('10', SpCredits::decimal(1_000_000, 100_000));
        $this->assertSame('9.99932', SpCredits::decimal(999_932, 100_000));
        $this->assertSame('0.00068', SpCredits::decimal(68, 100_000));
    }

    public function test_credit_display_converts_back_to_exact_raw_units(): void
    {
        $this->assertSame(1_000_000, SpCredits::rawFromDisplay('10', 100_000));
        $this->assertSame(999_932, SpCredits::rawFromDisplay('9.99932', 100_000));
        $this->assertSame(1, SpCredits::rawFromDisplay('0.00001', 100_000));
    }

    public function test_credit_precision_is_bounded(): void
    {
        $this->expectException(InvalidArgumentException::class);
        SpCredits::rawFromDisplay('1.000001', 100_000);
    }
}
