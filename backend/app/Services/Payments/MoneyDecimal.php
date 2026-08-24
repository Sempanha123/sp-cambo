<?php

namespace App\Services\Payments;

use InvalidArgumentException;

class MoneyDecimal
{
    public function toMinor(string $decimal, int $exponent): int
    {
        if (! preg_match('/^(0|[1-9][0-9]*)(?:\.([0-9]+))?$/', $decimal, $matches)) {
            throw new InvalidArgumentException('Invalid decimal money value.');
        }
        $fraction = $matches[2] ?? '';
        if (strlen($fraction) > $exponent && trim(substr($fraction, $exponent), '0') !== '') {
            throw new InvalidArgumentException('Money value has unsupported fractional precision.');
        }
        $fraction = str_pad(substr($fraction, 0, $exponent), $exponent, '0');
        $scale = 10 ** $exponent;
        $whole = (int) $matches[1];
        if ($whole > intdiv(PHP_INT_MAX, $scale)) {
            throw new InvalidArgumentException('Money value exceeds supported range.');
        }

        return ($whole * $scale) + (int) ($fraction === '' ? 0 : $fraction);
    }

    public function fromMinor(int $minor, int $exponent): string
    {
        $scale = 10 ** $exponent;
        if ($exponent === 0) {
            return (string) $minor;
        }

        return intdiv($minor, $scale).'.'.str_pad((string) ($minor % $scale), $exponent, '0', STR_PAD_LEFT);
    }
}
