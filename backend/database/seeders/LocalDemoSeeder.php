<?php

namespace Database\Seeders;

use App\Models\AiModel;
use App\Models\EntitlementLot;
use App\Models\ModelAlias;
use App\Models\Package;
use App\Models\Provider;
use App\Models\ProviderConnectionRevision;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;

class LocalDemoSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Create/Verify Roles
        $customerRole = Role::firstOrCreate(['name' => 'CUSTOMER']);
        $adminRole = Role::firstOrCreate(['name' => 'ADMIN']);

        // 2. Create Realistic Users
        $admin = User::firstOrCreate(
            ['email' => 'admin@spcambo.local'],
            [
                'name' => 'SP Cambo Admin',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
                'status' => 'ACTIVE',
            ]
        );
        $admin->roles()->sync([$adminRole->id]);

        $customer = User::firstOrCreate(
            ['email' => 'customer@spcambo.local'],
            [
                'name' => 'John Doe',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
                'status' => 'ACTIVE',
            ]
        );
        $customer->roles()->sync([$customerRole->id]);

        // 3. Create Realistic Providers
        $providers = [
            ['name' => 'Anthropic', 'slug' => 'anthropic'],
            ['name' => 'OpenAI', 'slug' => 'openai'],
            ['name' => 'Google AI', 'slug' => 'google'],
        ];

        foreach ($providers as $p) {
            $provider = Provider::firstOrCreate(['slug' => $p['slug']], ['name' => $p['name'], 'enabled' => true]);

            // Create an active connection revision for each
            $revision = ProviderConnectionRevision::create([
                'provider_id' => $provider->id,
                'route_version' => 1,
                'origin' => "https://api.{$p['slug']}.com/v1",
                'connection_type' => 'api_key',
                'credential' => encrypt("fake-{$p['slug']}-key"),
                'timeout_ms' => 60000,
                'policy_version' => '1',
                'lifecycle_status' => 'READY',
                'last_probe_status' => 'SUCCESS',
                'last_probe_at' => now(),
                'resolve_until' => now()->addYears(2),
            ]);
            $provider->update(['active_connection_revision_id' => $revision->id]);

            // 4. Create Realistic Models & Aliases
            $models = [];
            if ($p['slug'] === 'anthropic') {
                $models = [
                    ['id' => 'claude-3-5-sonnet-20240620', 'alias' => 'claude-3-5-sonnet', 'name' => 'Claude 3.5 Sonnet', 'family' => 'claude'],
                ];
            } elseif ($p['slug'] === 'openai') {
                $models = [
                    ['id' => 'gpt-4o-2024-05-13', 'alias' => 'gpt-4o', 'name' => 'GPT-4o', 'family' => 'gpt'],
                ];
            } elseif ($p['slug'] === 'google') {
                $models = [
                    ['id' => 'gemini-1.5-pro-002', 'alias' => 'gemini-1.5-pro', 'name' => 'Gemini 1.5 Pro', 'family' => 'gemini'],
                ];
            }

            foreach ($models as $m) {
                $aiModel = AiModel::firstOrCreate(
                    ['provider_id' => $provider->id, 'internal_model_id' => $m['id']],
                    ['family' => $m['family'], 'family_label' => ucwords($m['family']), 'commercial_resale_verified_at' => now(), 'enabled' => true]
                );

                $alias = ModelAlias::firstOrCreate(
                    ['public_alias' => $m['alias']],
                    [
                        'ai_model_id' => $aiModel->id,
                        'display_name' => $m['name'],
                        'description' => "Access to {$m['name']} with high limits.",
                        'capabilities' => ['streaming' => true, 'tools' => true, 'vision' => true],
                        'limits' => ['requests_per_minute' => 100, 'tokens_per_minute' => 200000],
                        'status' => 'available',
                        'enabled' => true,
                        'customer_visible' => true,
                    ]
                );

                // 5. Create Packages for these Aliases
                $durations = [
                    ['slug' => "{$m['alias']}-1day", 'name' => "{$m['name']} 1-Day", 'duration' => 86400, 'price' => 500, 'units' => 1000],
                    ['slug' => "{$m['alias']}-7day", 'name' => "{$m['name']} 7-Day", 'duration' => 604800, 'price' => 2500, 'units' => 5000],
                ];

                foreach ($durations as $d) {
                    $package = Package::firstOrCreate(
                        ['slug' => $d['slug']],
                        [
                            'name' => $d['name'],
                            'subtitle' => 'Full capability access',
                            'family' => $m['family'],
                            'family_label' => ucwords($m['family']),
                            'billing_mode' => 'TOKEN_QUOTA',
                            'advertised_units' => $d['units'],
                            'unit_label' => 'tokens',
                            'currency' => 'USD',
                            'currency_exponent' => 2,
                            'price_minor' => $d['price'],
                            'duration_seconds' => $d['duration'],
                            'auto_creates_api_key' => true,
                            'featured' => ($d['duration'] === 604800),
                            'sort_order' => 10,
                            'enabled' => true,
                            'customer_visible' => true,
                            'profitability_override_reason' => 'Standard Pricing',
                            'limits' => [
                                'requests_per_minute' => 60,
                                'tokens_per_minute' => 100000,
                                'concurrency' => 4,
                                'max_request_bytes' => 1048576,
                                'max_output_tokens' => 4096,
                            ],
                        ]
                    );
                    $package->modelAliases()->sync([$alias->id]);

                    // Give the customer a 7-day pass for Claude 3.5 Sonnet to start
                    if ($d['slug'] === 'claude-3-5-sonnet-7day') {
                        EntitlementLot::create([
                            'user_id' => $customer->id,
                            'source_type' => 'DEMO',
                            'source_id' => 'local-seeder',
                            'package_name' => $package->name,
                            'family_label' => $package->family_label,
                            'billing_mode' => $package->billing_mode,
                            'original_units' => $d['units'] * 1000, // units are usually in thousands for tokens
                            'remaining_units' => $d['units'] * 1000,
                            'unit_label' => $package->unit_label,
                            'currency' => $package->currency,
                            'currency_exponent' => $package->currency_exponent,
                            'allowed_model_aliases' => [$alias->public_alias],
                            'billing_snapshot' => [
                                'limits' => $package->limits,
                            ],
                            'activated_at' => now(),
                            'expires_at' => now()->addDays(7),
                            'status' => 'ACTIVE',
                        ]);
                    }
                }
            }
        }
    }
}