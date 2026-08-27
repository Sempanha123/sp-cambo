<?php

namespace Database\Seeders;

use App\Models\Package;
use Illuminate\Database\Seeder;

class PackageCatalogSeeder extends Seeder
{
    public function run(): void
    {
        $common = [
            'family' => 'codex',
            'family_label' => 'OpenAI Codex',
            'currency' => 'USD',
            'duration_seconds' => 30 * 24 * 60 * 60,
            'limits' => [
                'requests_per_minute' => 60,
                'tokens_per_minute' => 200000,
                'concurrency' => 4,
                'max_request_bytes' => 1048576,
                'max_output_tokens' => 16384,
            ],
            'billing_rules' => [],
            // Website customers choose Playground / new key / existing key after
            // payment. Telegram uses this flag to create a dedicated key directly.
            'auto_creates_api_key' => true,
            'minimum_margin_bps' => 0,
            'profitability_override_reason' => 'Local demo catalog for operator acceptance testing only.',
            // LocalDemoSeeder publishes these only after the real demo provider is READY.
            'enabled' => false,
            'customer_visible' => false,
        ];

        $packages = [
            [
                'slug' => 'demo-token-10m',
                'name' => 'OpenAI Codex 10M Tokens',
                'subtitle' => '10 million metered tokens for Playground or one API key.',
                'badge' => 'Demo',
                'billing_mode' => 'TOKEN_QUOTA',
                'advertised_units' => 10_000_000,
                'unit_label' => 'tokens',
                'price_minor' => 100,
                'currency_exponent' => 2,
                'featured' => false,
                'sort_order' => 10,
            ],
            [
                'slug' => 'demo-token-50m',
                'name' => 'OpenAI Codex 50M Tokens',
                'subtitle' => '50 million metered tokens for longer acceptance tests.',
                'badge' => 'Popular',
                'billing_mode' => 'TOKEN_QUOTA',
                'advertised_units' => 50_000_000,
                'unit_label' => 'tokens',
                'price_minor' => 500,
                'currency_exponent' => 2,
                'featured' => true,
                'sort_order' => 20,
            ],
            [
                'slug' => 'demo-credit-10usd',
                'name' => '$10 Model Credit',
                'subtitle' => '$10.00 of usage credit charged from model pricing.',
                'badge' => 'Credit',
                'billing_mode' => 'CREDIT_BALANCE',
                // CREDIT_BALANCE units use the lot currency exponent. 1000 with
                // exponent 2 is exactly USD 10.00 and is charged in minor units.
                'advertised_units' => 1_000,
                'unit_label' => 'USD credit',
                'price_minor' => 1_000,
                'currency_exponent' => 2,
                'featured' => false,
                'sort_order' => 30,
            ],
            [
                'slug' => 'demo-credit-100usd',
                'name' => '$100 Model Credit',
                'subtitle' => '$100.00 of usage credit charged from model pricing.',
                'badge' => 'Credit',
                'billing_mode' => 'CREDIT_BALANCE',
                'advertised_units' => 10_000,
                'unit_label' => 'USD credit',
                'price_minor' => 10_000,
                'currency_exponent' => 2,
                'featured' => false,
                'sort_order' => 40,
            ],
        ];

        foreach ($packages as $definition) {
            Package::query()->updateOrCreate(
                ['slug' => $definition['slug']],
                $common + $definition,
            );
        }
    }
}
