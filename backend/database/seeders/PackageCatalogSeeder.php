<?php

namespace Database\Seeders;

use App\Models\Package;
use Illuminate\Database\Seeder;

class PackageCatalogSeeder extends Seeder
{
    public function run(): void
    {
        foreach ([[5, 50], [10, 100], [20, 200], [50, 300], [100, 500], [200, 1000]] as [$millions, $priceMinor]) {
            Package::query()->updateOrCreate(['slug' => "token-quota-{$millions}m"], [
                'name' => "Token Quota {$millions}M",
                'subtitle' => 'Draft catalog seed; configure eligible models and verify margin before publishing.',
                'billing_mode' => 'TOKEN_QUOTA',
                'family' => 'configurable',
                'family_label' => 'Configurable',
                'advertised_units' => $millions * 1_000_000,
                'unit_label' => 'tokens',
                'price_minor' => $priceMinor,
                'currency' => 'USD',
                'currency_exponent' => 2,
                'duration_seconds' => 86400,
                'limits' => [],
                'auto_creates_api_key' => false,
                'featured' => false,
                'sort_order' => $millions,
                'enabled' => false,
                'customer_visible' => false,
            ]);
        }
    }
}
