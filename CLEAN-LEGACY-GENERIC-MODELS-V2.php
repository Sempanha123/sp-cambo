<?php
declare(strict_types=1);

/**
 * SP Cambo — CLEAN LEGACY GENERIC MODELS V2
 *
 * Targets ONLY these exact old private model IDs under omniroute-primary:
 *   Claude
 *   Deepseek
 *   Gemini
 *   Chatgpt
 *
 * Everything else is preserved.
 *
 * DRY RUN:
 *   php ./CLEAN-LEGACY-GENERIC-MODELS-V2.php
 *
 * APPLY:
 *   php ./CLEAN-LEGACY-GENERIC-MODELS-V2.php --apply
 */

$root = __DIR__;
$backend = $root . DIRECTORY_SEPARATOR . 'backend';

if (!is_file($backend . DIRECTORY_SEPARATOR . 'artisan')) {
    fwrite(STDERR, "ERROR: Put this file in the SP Cambo project root (the folder containing backend/ and frontend/).\n");
    exit(1);
}

require $backend . '/vendor/autoload.php';

$app = require $backend . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\AiModel;
use App\Models\ModelAlias;
use App\Models\ModelPricing;
use App\Models\Package;
use App\Models\PlaygroundSetting;
use App\Models\Provider;
use App\Models\RedeemCode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

const PROVIDER_SLUG = 'omniroute-primary';
const OLD_INTERNAL_IDS = ['Claude', 'Deepseek', 'Gemini', 'Chatgpt'];

$apply = in_array('--apply', $argv, true);

$provider = Provider::query()->where('slug', PROVIDER_SLUG)->first();
if (!$provider) {
    fwrite(STDERR, "ERROR: Provider '" . PROVIDER_SLUG . "' was not found.\n");
    exit(1);
}

$oldModels = $provider->models()
    ->whereIn('internal_model_id', OLD_INTERNAL_IDS)
    ->orderBy('internal_model_id')
    ->get();

$oldModelIds = $oldModels->pluck('id')->values();

$oldAliases = $oldModelIds->isEmpty()
    ? collect()
    : ModelAlias::query()
        ->whereIn('ai_model_id', $oldModelIds)
        ->orderBy('public_alias')
        ->get();

$oldAliasIds = $oldAliases->pluck('id')->values();
$oldAliasNames = $oldAliases->pluck('public_alias')->filter()->values()->all();

$packageIds = $oldAliasIds->isEmpty()
    ? collect()
    : DB::table('model_alias_package')
        ->whereIn('model_alias_id', $oldAliasIds)
        ->pluck('package_id')
        ->unique()
        ->values();

$legacyOnlyPackages = collect();
$mixedPackages = collect();

foreach ($packageIds as $packageId) {
    $allAliasIds = DB::table('model_alias_package')
        ->where('package_id', $packageId)
        ->pluck('model_alias_id');

    $hasNewAlias = $allAliasIds->contains(
        fn ($aliasId) => !$oldAliasIds->contains($aliasId)
    );

    $package = Package::query()->find($packageId);
    if (!$package) continue;

    if ($hasNewAlias) {
        $mixedPackages->push($package);
    } else {
        $legacyOnlyPackages->push($package);
    }
}

echo "============================================================\n";
echo "SP Cambo — LEGACY GENERIC MODEL CLEANUP V2\n";
echo "============================================================\n";
echo "Provider: {$provider->name} ({$provider->slug})\n";
echo "Mode: " . ($apply ? "APPLY / PERMANENT" : "DRY RUN / NO CHANGES") . "\n\n";

echo "EXACT OLD PRIVATE MODELS TO DELETE:\n";
if ($oldModels->isEmpty()) {
    echo "  (none found)\n";
} else {
    foreach ($oldModels as $model) {
        $aliasCount = $oldAliases->where('ai_model_id', $model->id)->count();
        echo "  DELETE  {$model->internal_model_id}  [db_id={$model->id}, aliases={$aliasCount}]\n";
    }
}

echo "\nPUBLIC ALIASES MAPPED TO THOSE OLD MODELS:\n";
if ($oldAliases->isEmpty()) {
    echo "  (none)\n";
} else {
    foreach ($oldAliases as $alias) {
        echo "  DELETE  {$alias->public_alias}  [alias_id={$alias->id}]\n";
    }
}

echo "\nPACKAGES BELONGING ONLY TO OLD ALIASES:\n";
if ($legacyOnlyPackages->isEmpty()) {
    echo "  (none)\n";
} else {
    foreach ($legacyOnlyPackages as $package) {
        echo "  DELETE  {$package->slug}  [package_id={$package->id}, name={$package->name}]\n";
    }
}

echo "\nMIXED PACKAGES (OLD + NEW ALIASES):\n";
if ($mixedPackages->isEmpty()) {
    echo "  (none)\n";
} else {
    foreach ($mixedPackages as $package) {
        echo "  KEEP    {$package->slug}  [detach old alias only]\n";
    }
}

echo "\nALL OTHER PRIVATE MODELS / ALIASES / PACKAGES ARE PRESERVED.\n";

$seederPath = $backend . '/database/seeders/SellCatalogSeeder.php';
if (is_file($seederPath)) {
    $seeder = (string) file_get_contents($seederPath);
    $stillInSeeder = array_values(array_filter(
        OLD_INTERNAL_IDS,
        static fn (string $id): bool =>
            str_contains($seeder, "'internal_model_id' => '{$id}'")
            || str_contains($seeder, "\"internal_model_id\" => \"{$id}\"")
    ));

    if ($stillInSeeder !== []) {
        echo "\n!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!\n";
        echo "WARNING: Your current SellCatalogSeeder still contains old IDs:\n";
        foreach ($stillInSeeder as $id) echo "  - {$id}\n";
        echo "DO NOT run SellCatalogSeeder after cleanup until that seeder is\n";
        echo "updated to your NEW exact private model IDs, or the old rows can\n";
        echo "be created again.\n";
        echo "!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!\n";
    }
}

if (!$apply) {
    echo "\nDRY RUN COMPLETE — DATABASE WAS NOT CHANGED.\n";
    echo "If the list above is correct, run:\n";
    echo "  php ./CLEAN-LEGACY-GENERIC-MODELS-V2.php --apply\n";
    exit(0);
}

if ($oldModels->isEmpty()) {
    echo "\nNothing to delete. The four old generic private models are already gone.\n";
    exit(0);
}

DB::transaction(function () use (
    $oldModels,
    $oldModelIds,
    $oldAliases,
    $oldAliasIds,
    $oldAliasNames,
    $legacyOnlyPackages,
    $mixedPackages
): void {
    /*
     * 1) Remove route-pool entries targeting the legacy private models.
     * reservations.model_route_pool_entry_id uses nullOnDelete, so request
     * history remains intact.
     */
    if (
        !$oldModelIds->isEmpty()
        && Schema::hasTable('model_route_pool_entries')
        && Schema::hasColumn('model_route_pool_entries', 'ai_model_id')
    ) {
        DB::table('model_route_pool_entries')
            ->whereIn('ai_model_id', $oldModelIds)
            ->delete();
    }

    /*
     * 2) Delete packages that contain ONLY old aliases.
     * order_items.package_id is nullable/nullOnDelete and keeps package_snapshot,
     * so historical order text/pricing remains preserved.
     */
    foreach ($legacyOnlyPackages as $package) {
        $package->delete();
    }

    /*
     * 3) Mixed package: preserve package, detach only old aliases.
     */
    foreach ($mixedPackages as $package) {
        DB::table('model_alias_package')
            ->where('package_id', $package->id)
            ->whereIn('model_alias_id', $oldAliasIds)
            ->delete();
    }

    /*
     * 4) Remove current API-key access mappings to old aliases.
     */
    if (!$oldAliasIds->isEmpty() && Schema::hasTable('api_key_model_alias')) {
        DB::table('api_key_model_alias')
            ->whereIn('model_alias_id', $oldAliasIds)
            ->delete();
    }

    /*
     * 5) Remove pricing attached to old aliases.
     */
    if (!$oldAliasIds->isEmpty()) {
        ModelPricing::query()
            ->whereIn('model_alias_id', $oldAliasIds)
            ->delete();
    }

    /*
     * 6) Remove the old alias names from Playground config.
     */
    if ($oldAliasNames !== []) {
        PlaygroundSetting::query()->each(function (PlaygroundSetting $setting) use ($oldAliasNames): void {
            $allowed = array_values(array_filter(
                $setting->allowed_model_aliases ?? [],
                static fn ($value): bool =>
                    is_string($value) && !in_array($value, $oldAliasNames, true)
            ));

            $updates = ['allowed_model_aliases' => $allowed];

            if (
                is_string($setting->default_model_alias)
                && in_array($setting->default_model_alias, $oldAliasNames, true)
            ) {
                $updates['default_model_alias'] = null;
            }

            $setting->update($updates);
        });

        /*
         * 7) Remove old alias names from redeem codes. If a code is left with
         * no models, disable that code rather than leaving an unusable grant.
         */
        RedeemCode::query()->each(function (RedeemCode $code) use ($oldAliasNames): void {
            $before = array_values(array_filter(
                $code->allowed_model_aliases ?? [],
                'is_string'
            ));

            $after = array_values(array_filter(
                $before,
                static fn (string $value): bool =>
                    !in_array($value, $oldAliasNames, true)
            ));

            if ($before === $after) return;

            $updates = ['allowed_model_aliases' => $after];
            if ($after === []) $updates['enabled'] = false;

            $code->update($updates);
        });
    }

    /*
     * 8) Delete old public aliases. Their model_route_pools cascade on alias
     * deletion.
     */
    foreach ($oldAliases as $alias) {
        $alias->delete();
    }

    /*
     * 9) Final guard: old models must now have zero aliases and zero route
     * targets before physical deletion.
     */
    foreach ($oldModels as $model) {
        $aliasCount = ModelAlias::query()
            ->where('ai_model_id', $model->id)
            ->count();

        if ($aliasCount !== 0) {
            throw new RuntimeException(
                "Refusing to delete {$model->internal_model_id}: {$aliasCount} aliases still reference it."
            );
        }

        if (
            Schema::hasTable('model_route_pool_entries')
            && Schema::hasColumn('model_route_pool_entries', 'ai_model_id')
        ) {
            $routeCount = DB::table('model_route_pool_entries')
                ->where('ai_model_id', $model->id)
                ->count();

            if ($routeCount !== 0) {
                throw new RuntimeException(
                    "Refusing to delete {$model->internal_model_id}: {$routeCount} route-pool entries still reference it."
                );
            }
        }

        $model->delete();
    }
});

echo "\n✅ CLEANUP COMPLETE\n";
echo "Deleted ONLY exact old private IDs:\n";
foreach (OLD_INTERNAL_IDS as $id) echo "  - {$id}\n";
echo "\n✅ Their old aliases were removed.\n";
echo "✅ Packages containing only those old aliases were deleted.\n";
echo "✅ Mixed/new packages were preserved.\n";
echo "✅ Other new private models were preserved.\n";
echo "✅ OmniRoute provider + Connection 1/2 were preserved.\n";
echo "✅ Historical orders/request usage were preserved.\n\n";
echo "Now run:\n";
echo "  cd backend\n";
echo "  php artisan optimize:clear\n";
echo "\nIMPORTANT: Do NOT run SellCatalogSeeder if it still defines Claude/Deepseek/Gemini/Chatgpt.\n";
