<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('packages')
            ->select(['id', 'name', 'subtitle', 'billing_rules'])
            ->orderBy('id')
            ->get()
            ->each(function ($package): void {
                $rules = is_string($package->billing_rules)
                    ? json_decode($package->billing_rules, true)
                    : $package->billing_rules;

                if (! is_array($rules) || ($rules['package_kind'] ?? null) !== 'SP_CREDITS') {
                    return;
                }

                $clean = static fn (?string $value): ?string => $value === null
                    ? null
                    : (preg_replace(
                        '/\$(?=\d[\d,]*(?:\.\d+)?\s+Credits?\b)/i',
                        '',
                        $value
                    ) ?? $value);

                DB::table('packages')
                    ->where('id', $package->id)
                    ->update([
                        'name' => $clean((string) $package->name),
                        'subtitle' => $clean($package->subtitle),
                    ]);
            });
    }

    public function down(): void
    {
        // Presentation normalization is intentionally not reversed. The old "$"
        // implied USD face value that SP Credits never represented.
    }
};
