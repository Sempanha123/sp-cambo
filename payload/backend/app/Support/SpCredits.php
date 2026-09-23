<?php

namespace App\Support;

use App\Models\EntitlementLot;
use InvalidArgumentException;

final class SpCredits
{
    /**
     * One displayed SP Credit contains 100,000 exact settlement sub-units.
     *
     * These are internal precision units, not a second customer-facing Token
     * balance and not USD cents. Existing inference settlement continues to use
     * integers, while every customer/reseller surface can display Credits.
     */
    public const RAW_UNITS_PER_CREDIT = 100_000;

    public static function isSnapshot(?array $snapshot): bool
    {
        $snapshot = is_array($snapshot) ? $snapshot : [];
        $rules = is_array($snapshot['billing_rules'] ?? null)
            ? $snapshot['billing_rules']
            : [];

        return ($rules['package_kind'] ?? null) === 'SP_CREDITS'
            || in_array(($rules['display_unit_label'] ?? null), ['Credits', 'SP Credits'], true);
    }

    public static function isLot(EntitlementLot $lot): bool
    {
        return self::isSnapshot(
            is_array($lot->billing_snapshot) ? $lot->billing_snapshot : []
        );
    }

    public static function rawUnitsPerDisplayUnit(EntitlementLot $lot): int
    {
        if (self::isLot($lot)) {
            $snapshot = is_array($lot->billing_snapshot) ? $lot->billing_snapshot : [];
            $rules = is_array($snapshot['billing_rules'] ?? null)
                ? $snapshot['billing_rules']
                : [];

            return max(
                1,
                (int) ($rules['sp_credit_billable_units'] ?? self::RAW_UNITS_PER_CREDIT)
            );
        }

        return 1;
    }

    public static function cleanLabel(string $value): string
    {
        // Historical package snapshots used names such as "Codex $50 Credits".
        // "$50" incorrectly looks like fifty US dollars of wallet value. SP
        // Credits are platform usage Credits, so remove only that misleading "$".
        return preg_replace(
            '/\$(?=\d[\d,]*(?:\.\d+)?\s+Credits?\b)/i',
            '',
            $value
        ) ?? $value;
    }

    public static function decimal(int|string $rawUnits, int $scale): string
    {
        $raw = max(0, (int) $rawUnits);
        $scale = max(1, $scale);

        if ($scale === 1) {
            return (string) $raw;
        }

        $precision = self::precision($scale);
        $whole = intdiv($raw, $scale);
        $remainder = $raw % $scale;

        if ($remainder === 0) {
            return (string) $whole;
        }

        $fraction = str_pad((string) $remainder, $precision, '0', STR_PAD_LEFT);

        return rtrim(rtrim($whole.'.'.$fraction, '0'), '.');
    }

    public static function rawFromDisplay(string $displayUnits, int $scale): int
    {
        $displayUnits = trim($displayUnits);
        $scale = max(1, $scale);

        if (! preg_match('/^\d+(?:\.\d+)?$/', $displayUnits)) {
            throw new InvalidArgumentException('Enter a positive quantity.');
        }

        $precision = self::precision($scale);
        [$whole, $fraction] = array_pad(explode('.', $displayUnits, 2), 2, '');

        if (strlen($fraction) > $precision) {
            throw new InvalidArgumentException(
                "This package supports at most {$precision} decimal places."
            );
        }

        $wholeInt = (int) $whole;
        if ($wholeInt !== 0 && $scale > intdiv(PHP_INT_MAX, $wholeInt)) {
            throw new InvalidArgumentException('Quantity is too large.');
        }

        $raw = $wholeInt * $scale;

        if ($fraction !== '') {
            $fractionRaw = (int) str_pad($fraction, $precision, '0', STR_PAD_RIGHT);
            if ($raw > PHP_INT_MAX - $fractionRaw) {
                throw new InvalidArgumentException('Quantity is too large.');
            }
            $raw += $fractionRaw;
        }

        if ($raw < 1) {
            throw new InvalidArgumentException('Sell more than zero.');
        }

        return $raw;
    }

    /**
     * Customer-facing representation of a quota-backed lot.
     *
     * @param array{original_units?:int|string,remaining_units?:int|string,reserved_units?:int|string}|null $override
     * @return array<string,string|null>
     */
    public static function displayLot(
        EntitlementLot $lot,
        int|string|null $soldRawUnits = null,
        ?array $override = null,
    ): array {
        $scale = self::rawUnitsPerDisplayUnit($lot);
        $original = (int) ($override['original_units'] ?? $lot->original_units);
        $remaining = (int) ($override['remaining_units'] ?? $lot->remaining_units);
        $reserved = (int) ($override['reserved_units'] ?? $lot->reserved_units);
        $available = max(0, $remaining - $reserved);

        return [
            'kind' => self::isLot($lot) ? 'SP_CREDITS' : 'SP_TOKENS',
            'unit_label' => self::isLot($lot) ? 'Credits' : (string) ($lot->unit_label ?: 'Tokens'),
            'raw_units_per_display_unit' => (string) $scale,
            'original' => self::decimal($original, $scale),
            'remaining' => self::decimal($remaining, $scale),
            'reserved' => self::decimal($reserved, $scale),
            'available' => self::decimal($available, $scale),
            'sold' => $soldRawUnits === null
                ? null
                : self::decimal((int) $soldRawUnits, $scale),
        ];
    }

    private static function precision(int $scale): int
    {
        if ($scale <= 1) {
            return 0;
        }

        $precision = 0;
        $value = $scale;

        while ($value > 1 && $value % 10 === 0) {
            $value = intdiv($value, 10);
            $precision++;
        }

        if ($value !== 1) {
            throw new InvalidArgumentException(
                'SP Credit display scale must be a power of ten.'
            );
        }

        return $precision;
    }
}
