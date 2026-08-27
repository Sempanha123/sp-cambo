<?php

namespace Database\Seeders;

use App\Models\ModelAlias;
use App\Models\Package;
use App\Models\PlaygroundSetting;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class LocalDemoSeeder extends Seeder
{
    public function run(): void
    {
        $adminRole = Role::query()->updateOrCreate(['name' => 'ADMIN'], ['label' => 'Administrator']);
        $customerRole = Role::query()->updateOrCreate(['name' => 'CUSTOMER'], ['label' => 'Customer']);

        $admin = User::query()->updateOrCreate(
            ['email' => 'admin@spcambo.local'],
            [
                'name' => 'SP Cambo Admin',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
                'status' => 'ACTIVE',
            ],
        );
        // Local acceptance uses this account for both admin and customer surfaces.
        $admin->roles()->syncWithoutDetaching([$adminRole->id, $customerRole->id]);

        $customer = User::query()->updateOrCreate(
            ['email' => 'customer@spcambo.local'],
            [
                'name' => 'Demo Customer',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
                'status' => 'ACTIVE',
            ],
        );
        $customer->roles()->syncWithoutDetaching([$customerRole->id]);

        $publicAlias = trim((string) config('services.spcambo.demo_public_alias', 'openai-codex')) ?: 'openai-codex';
        $alias = ModelAlias::query()->where('public_alias', $publicAlias)->first();
        if (! $alias) {
            $this->command?->warn('Demo public model alias is missing. Run ProviderSeeder before LocalDemoSeeder.');

            return;
        }

        // Configure the 20K daily allowance even when the upstream is temporarily
        // offline. PlaygroundService reports the untouched allowance separately
        // from model availability, so 0/20K never means "used" when no route exists.
        PlaygroundSetting::current()->forceFill([
            'enabled' => true,
            'daily_token_quota' => 20_000,
            'max_output_tokens' => 16_384,
            'allowed_model_aliases' => [$publicAlias],
            'gateway_base_url' => rtrim((string) config('services.spcambo.gateway_base_url', 'http://127.0.0.1:3010'), '/'),
            'default_model_alias' => $publicAlias,
            'allow_model_switching' => true,
        ])->save();

        $sellable = ModelAlias::query()->published()->whereKey($alias->id)->exists();
        foreach (['demo-token-10m', 'demo-token-50m', 'demo-credit-10usd', 'demo-credit-100usd'] as $slug) {
            $package = Package::query()->where('slug', $slug)->first();
            if (! $package) {
                continue;
            }

            $package->forceFill([
                'family' => $alias->model?->family ?? 'codex',
                'family_label' => $alias->model?->family_label ?? 'OpenAI Codex',
                'enabled' => $sellable,
                'customer_visible' => $sellable,
            ])->save();
            $package->modelAliases()->sync([$alias->id]);
        }

        if ($this->command) {
            $this->command->info('Local demo users: admin@spcambo.local / customer@spcambo.local (password: password).');
            $this->command->info('Daily Playground quota: 20,000 tokens for '.$publicAlias.'.');
            if ($sellable) {
                $this->command->info('Demo store published: 10M tokens, 50M tokens, $10 credit, $100 credit.');
            } else {
                $this->command->warn('Demo packages remain hidden because the configured upstream/model is not publishable yet. Fix the provider probe and reseed.');
            }
        }
    }
}
