<?php

use App\Exceptions\PaymentException;
use App\Models\PaymentAttempt;
use App\Models\Role;
use App\Models\SystemHeartbeat;
use App\Models\User;
use App\Services\AuditService;
use App\Services\EntitlementService;
use App\Services\PaymentService;
use App\Services\PackageStockService;
use App\Services\ReservationService;
use App\Services\TelegramCommerceService;
use App\Services\TelegramAnnouncementService;
use App\Services\TelegramPurchaseAlertService;
use App\Support\AccessAllocationSchema;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('spcambo:grant-role {email} {role} {--reason=}', function (): int {
    $email = mb_strtolower(trim((string) $this->argument('email')));
    $roleName = mb_strtoupper(trim((string) $this->argument('role')));
    $reason = trim((string) $this->option('reason'));

    $user = User::query()->whereRaw('LOWER(email) = ?', [$email])->first();
    if (! $user) {
        $this->error('No user exists with that email address.');

        return 1;
    }

    $role = Role::query()->where('name', $roleName)->first();
    if (! $role) {
        $this->error('The requested role does not exist in the canonical RBAC baseline.');

        return 1;
    }

    if ($reason === '') {
        $this->error('A non-empty --reason is required for every role grant.');

        return 1;
    }

    $granted = DB::transaction(function () use ($user, $role, $reason): bool {
        $attached = DB::table('role_user')->insertOrIgnore([
            'role_id' => $role->id,
            'user_id' => $user->id,
        ]);
        if ($attached === 0) {
            return false;
        }

        app(AuditService::class)->recordSystem(
            'authorization.role.granted',
            'user',
            $user->id,
            $reason,
            [
                'role' => $role->name,
                'source' => 'artisan',
            ],
        );

        return true;
    });

    $this->info($granted
        ? "Granted {$role->name} to {$user->email}."
        : "{$user->email} already has {$role->name}.");

    return 0;
})->purpose('Grant a canonical role to an existing user with an immutable audit record');

Artisan::command('billing:recover-stale-reservations {--batch=100}', function (): int {
    $recovered = app(ReservationService::class)->recoverStale((int) $this->option('batch'));
    $this->info("Recovered {$recovered} stale reservation(s).");

    return 0;
})->purpose('Release only expired ACTIVE inference reservations; reconciliation holds require explicit resolution');

Artisan::command('billing:expire-entitlements {--batch=100}', function (): int {
    $expired = app(EntitlementService::class)->expireDue((int) $this->option('batch'));
    $this->info("Expired {$expired} entitlement lot(s).");

    return 0;
})->purpose('Expire due entitlement lots atomically');

Artisan::command('payments:reconcile-pending {--batch=1}', function (): int {
    $batch = (int) $this->option('batch');

    if ($batch < 1 || $batch > 4) {
        $this->error('Payment reconciliation batch must be between 1 and 4 to stay within the Bakong API rate budget.');

        return 2;
    }

    $result = app(PaymentService::class)->reconcilePending($batch);
    $this->info("Checked {$result['checked']} payment(s); {$result['settled']} settled; {$result['waiting']} still waiting; {$result['failed']} failed.");

    foreach ($result['errors'] as $error) {
        $this->warn("Attempt {$error['attempt_id']}: {$error['code']} - {$error['message']}");
    }

    if ($result['checked'] === 0) {
        $this->line('No payment was due for automatic reconciliation. Recent attempts are rate-limited by the configured interval, and expired attempts older than the grace window require payments:verify-attempt.');
    }

    return $result['failed'] === 0 ? 0 : 1;
})->purpose('Reconcile pending Bakong payment attempts within the API rate budget');

Artisan::command('payments:verify-attempt {attempt} {--reason=Operator recovery of a previously paid or expired QR}', function (): int {
    $attemptId = trim((string) $this->argument('attempt'));
    $reason = trim((string) $this->option('reason'));

    if ($attemptId === '') {
        $this->error('A payment attempt ID is required.');

        return 2;
    }

    if ($reason === '') {
        $this->error('A non-empty --reason is required for manual payment verification.');

        return 2;
    }

    $attempt = PaymentAttempt::query()->with('order')->find($attemptId);
    if (! $attempt) {
        $this->error('Payment attempt was not found.');

        return 1;
    }

    app(AuditService::class)->recordSystem(
        'payment.manual_verification_requested',
        'payment_attempt',
        $attempt->id,
        $reason,
        [
            'order_id' => $attempt->order_id,
            'previous_status' => $attempt->status,
            'expired' => $attempt->expires_at?->isPast() ?? false,
        ],
    );

    $this->info("Checking payment attempt {$attempt->id} (current status: {$attempt->status}).");

    try {
        $verified = app(PaymentService::class)->verify($attempt);
    } catch (PaymentException $exception) {
        $message = $exception->operatorMessage ?? $exception->getMessage();
        $this->error("{$exception->errorCode} - {$message}");

        return 1;
    } catch (Throwable $exception) {
        report($exception);
        $this->error('Unexpected verification failure. Inspect storage/logs/laravel.log for the server-side exception.');

        return 1;
    }

    $verified->loadMissing('order');
    $orderStatus = $verified->order?->status ?? 'UNKNOWN';

    if ($verified->status === 'PAID' || $orderStatus === 'FULFILLED') {
        $this->info("Payment verified successfully. Attempt status: {$verified->status}; order status: {$orderStatus}.");

        return 0;
    }

    $this->warn("Bakong did not confirm a matching transfer. Attempt status: {$verified->status}; order status: {$orderStatus}. Do not mark the order paid manually.");

    return 0;
})->purpose('Safely re-check one specific payment attempt, including an expired attempt outside the automatic grace window');



Artisan::command('catalog:release-expired-stock-reservations {--batch=100}', function (): int {
    $result = app(PackageStockService::class)->releaseExpired((int) $this->option('batch'));
    $this->info("Checked {$result['checked']} stock reservation(s); released {$result['released']}.");

    return 0;
})->purpose('Release limited package stock held by abandoned unpaid orders');

Artisan::command('telegram:reconcile-purchases {--batch=4}', function (): int {
    $result = app(TelegramCommerceService::class)->reconcilePending((int) $this->option('batch'));
    $this->info("Checked {$result['checked']} Telegram purchase(s); {$result['failed']} failed.");

    return $result['failed'] === 0 ? 0 : 1;
})->purpose('Verify Telegram-originated payments and deliver fulfilled API access');

Artisan::command('telegram:broadcast-announcements {--batch=50}', function (): int {
    $result = app(TelegramAnnouncementService::class)->dispatchPending((int) $this->option('batch'));
    $this->info("Processed {$result['announcements']} announcement(s); attempted {$result['attempted']}, sent {$result['sent']}, failed {$result['failed']}.");

    return $result['failed'] === 0 ? 0 : 1;
})->purpose('Deliver queued new-model, new-package, package-update and manual Telegram storefront announcements');

Artisan::command('telegram:dispatch-purchase-alerts {--batch=50}', function (): int {
    $service = app(TelegramPurchaseAlertService::class);
    $recovered = $service->recoverMissingPublicEvents(max(10, (int) $this->option('batch')));
    $result = $service->dispatchPending((int) $this->option('batch'));
    $this->info("Recovered {$recovered} missing public event(s). Checked {$result['checked']} purchase alert(s); sent {$result['sent']}, failed {$result['failed']}.");

    // Delivery failures are already persisted as FAILED + retry_after. Returning
    // non-zero here makes Laravel report the entire scheduler as failed even though
    // commerce succeeded and retry state is healthy. Telegram outages must not
    // poison the scheduler heartbeat or bury unrelated application errors.
    return 0;
})->purpose('Cancel legacy Fix17 purchase-alert outbox rows; Fix19 website orders are Telegram-silent');

Artisan::command('telegram:dispatch-public-purchase-feed {--batch=50}', function (): int {
    $service = app(TelegramPurchaseAlertService::class);
    $recovered = $service->recoverMissingPublicEvents(max(10, (int) $this->option('batch')));
    $this->info("Recovered {$recovered} missing R13 public purchase event(s). Public rows are delivered by telegram:dispatch-purchase-alerts.");

    return 0;
})->purpose('Recover missing Telegram Store purchase-activity subscriber announcements only');

Artisan::command('system:check-access-allocation-schema', function (): int {
    if (AccessAllocationSchema::ready()) {
        $this->info('Access-allocation schema is ready.');
        return 0;
    }

    $this->error('Access-allocation schema is incomplete. Missing: '.implode(', ', AccessAllocationSchema::missing()));
    $this->line('Run: php artisan migrate --force');

    return 1;
})->purpose('Verify the Fix 7 access-allocation database columns required by Playground and API key details');

Artisan::command('catalog:sell-status', function (): int {
    $provider = \App\Models\Provider::query()->where('slug', 'omniroute-primary')->with('activeConnectionRevision')->first();
    $expectedAliases = ['openai-codex', 'gemini-google-ai-studio'];
    $expectedInternalModels = [
        'openai-codex' => 'OpenAI Codex',
        'gemini-google-ai-studio' => 'Gemini Google AI Studio',
    ];
    $packageSlugs = [
        'openai-codex-10m',
        'openai-codex-50m',
        'gemini-google-ai-studio-10m',
        'gemini-google-ai-studio-50m',
        'multi-model-credit-10usd',
        'multi-model-credit-100usd',
    ];
    $aliases = \App\Models\ModelAlias::query()->with('model')->whereIn('public_alias', $expectedAliases)->get()->keyBy('public_alias');
    $setting = \App\Models\PlaygroundSetting::current();

    $routeReady = $provider?->activeConnectionRevision?->isRouteReady() ?? false;
    $published = collect($expectedAliases)->filter(function (string $alias) use ($aliases): bool {
        $row = $aliases->get($alias);
        return $row !== null && \App\Models\ModelAlias::query()->published()->whereKey($row->id)->exists();
    });
    $publishedPackages = \App\Models\Package::query()->published()->whereIn('slug', $packageSlugs)->count();

    $this->line('ANTHROPIC_BASE_URL: '.(filled(config('services.spcambo.sell_catalog_base_url')) ? 'configured' : 'MISSING'));
    $this->line('ANTHROPIC_AUTH_TOKEN: '.(filled(config('services.spcambo.sell_catalog_token')) ? 'configured (redacted)' : 'MISSING'));
    $this->line('Model routing source: DATABASE (public alias -> exact AiModel.internal_model_id)');
    $this->line('Provider route: '.($routeReady ? 'READY' : 'NOT READY'));
    $protocolsValid = true;
    $modelMappingsValid = true;
    foreach ($expectedAliases as $aliasName) {
        $alias = $aliases->get($aliasName);
        $protocols = $alias ? collect([
            'messages' => (bool) ($alias->capabilities['messages_api'] ?? false),
            'responses' => (bool) ($alias->capabilities['responses_api'] ?? false),
            'chat_completions' => (bool) ($alias->capabilities['chat_completions_api'] ?? false),
        ])->filter()->keys()->values() : collect();
        $protocolsValid = $protocolsValid && $protocols->isNotEmpty();
        $protocolLabel = $protocols->isEmpty() ? 'NONE' : $protocols->implode(',');
        $playgroundProtocol = is_string($alias?->capabilities['playground_protocol'] ?? null)
            ? $alias->capabilities['playground_protocol']
            : ($protocols->first() ?: 'NONE');
        $internalModel = $alias?->model?->internal_model_id ?: 'MISSING';
        $mappingExact = hash_equals($expectedInternalModels[$aliasName], (string) $internalModel);
        $modelMappingsValid = $modelMappingsValid && $mappingExact;
        $this->line('Model '.$aliasName.' -> '.$internalModel.': '.($alias !== null && $published->contains($aliasName) ? 'PUBLISHED' : 'BLOCKED').' · verified '.$protocolLabel.' · Playground '.$playgroundProtocol.($mappingExact ? '' : ' · WRONG INTERNAL MODEL ID'));
    }
    $configuredFreeModels = array_values(array_intersect($expectedAliases, $setting->allowed_model_aliases ?? []));
    $this->line('Daily Playground quota: '.number_format((int) $setting->daily_token_quota).' · '.count($configuredFreeModels).'/2 models configured');
    $this->line("Sell products published: {$publishedPackages}/6");

    if (! $routeReady || $published->count() !== 2 || $publishedPackages !== 6 || ! $protocolsValid || ! $modelMappingsValid) {
        $this->warn('Run php artisan db:seed --class=SellCatalogSeeder --force after confirming ANTHROPIC_BASE_URL / ANTHROPIC_AUTH_TOKEN and both exact OmniRoute custom model IDs are available.');
    }

    return $routeReady && $published->count() === 2 && $publishedPackages === 6 && $protocolsValid && $modelMappingsValid ? 0 : 1;
})->purpose('Show safe two-model sell catalog readiness without printing credentials');

Artisan::command('system:heartbeat', function (): int {
    SystemHeartbeat::query()->updateOrCreate(['component' => 'scheduler'], ['recorded_at' => now()]);

    return 0;
})->purpose('Record the scheduler heartbeat');

Artisan::command('playground:prune-chats', function (): int {
    if (! \Illuminate\Support\Facades\Schema::hasTable('playground_chats')) {
        return 0;
    }

    $expired = \App\Models\PlaygroundChat::query()
        ->whereNotNull('expires_at')
        ->where('expires_at', '<=', now())
        ->delete();

    $this->info('Expired Playground chats removed: '.$expired);
    return 0;
})->purpose('Delete Playground chats after their rolling retention window');

Schedule::command('playground:prune-chats')
    ->dailyAt('03:20')
    ->withoutOverlapping();

Schedule::command('billing:recover-stale-reservations')
    ->everyMinute()
    ->withoutOverlapping();

Schedule::command('billing:expire-entitlements')
    ->everyMinute()
    ->withoutOverlapping();

Schedule::command('payments:reconcile-pending --batch=1')
    ->everyMinute()
    ->withoutOverlapping();


Schedule::command('catalog:release-expired-stock-reservations --batch=100')
    ->everyMinute()
    ->withoutOverlapping();

Schedule::command('telegram:reconcile-purchases --batch=4')
    ->everyMinute()
    ->withoutOverlapping();

Schedule::command('telegram:broadcast-announcements --batch=50')
    ->everyMinute()
    ->withoutOverlapping();

Schedule::command('telegram:dispatch-purchase-alerts --batch=50')
    ->everyMinute()
    ->withoutOverlapping();

Schedule::command('system:heartbeat')->everyMinute()->withoutOverlapping();

Artisan::command('system:diagnose-customer-access {email} {--key=}', function (): int {
    $email = mb_strtolower(trim((string) $this->argument('email')));
    $user = User::query()->whereRaw('LOWER(email) = ?', [$email])->first();
    if (! $user) {
        $this->error('No user exists with that email address.');
        return 1;
    }

    $this->line('Access-allocation schema: '.(AccessAllocationSchema::ready() ? 'READY' : 'NOT READY'));
    if (! AccessAllocationSchema::ready()) {
        $this->error('Missing: '.implode(', ', AccessAllocationSchema::missing()));
        return 1;
    }

    $failed = false;
    try {
        $quota = app(\App\Services\PlaygroundService::class)->quota($user);
        $this->info('Playground quota: OK');
        $this->line('  enabled='.(($quota['enabled'] ?? false) ? 'yes' : 'no'));
        $this->line('  daily_remaining='.(string) ($quota['remaining'] ?? 0));
        $this->line('  available_models='.count((array) ($quota['available_models'] ?? [])));
        $this->line('  purchased_models='.count((array) ($quota['fallback_model_aliases'] ?? [])));
        $blocked = (array) ($quota['unavailable_funded_models'] ?? []);
        $this->line('  unavailable_funded_models='.count($blocked));
        foreach ($blocked as $row) {
            if (! is_array($row)) continue;
            $this->line('    - '.(string) ($row['public_alias'] ?? 'unknown').' | tokens='.(string) ($row['token_remaining'] ?? 0).' | '.(string) ($row['reason'] ?? 'temporarily unavailable'));
        }
    } catch (\Throwable $exception) {
        $failed = true;
        $this->error('Playground quota: FAILED');
        $this->line('  '.get_class($exception).': '.\Illuminate\Support\Str::limit($exception->getMessage(), 1200));
    }

    $keyId = trim((string) $this->option('key'));
    if ($keyId !== '' && str_starts_with($keyId, 'sk-spc-')) {
        $this->error('The --key option expects the API key ID shown in the dashboard URL, not the secret key. Rotate any secret pasted into a terminal/chat transcript.');
        return 1;
    }

    $keyQuery = \App\Models\ApiKey::query()
        ->where('user_id', $user->id)
        ->whereNotIn('id', \App\Models\PlaygroundCredential::query()->select('api_key_id'));
    $key = $keyId !== '' ? $keyQuery->whereKey($keyId)->first() : $keyQuery->latest()->first();

    if (! $key) {
        $this->warn('API key details: SKIPPED (no matching customer API key)');
    } else {
        try {
            $request = \Illuminate\Http\Request::create('/api/v1/me/api-keys/'.$key->id, 'GET');
            $request->setUserResolver(fn () => $user);
            $response = app(\App\Http\Controllers\Api\V1\ApiKeyController::class)->show($request, $key);
            $payload = $response->getData(true);
            if ($response->getStatusCode() >= 400) {
                $failed = true;
                $this->error('API key details: FAILED HTTP '.$response->getStatusCode());
                $this->line('  '.(string) ($payload['message'] ?? $payload['code'] ?? 'Unknown error'));
            } else {
                $this->info('API key details: OK');
                $this->line('  key_id='.$key->id);
                $this->line('  models='.count((array) ($payload['data']['key']['allowed_model_aliases'] ?? [])));
                $this->line('  funding_lots='.count((array) ($payload['data']['funding'] ?? [])));
                $this->line('  token_remaining='.(string) ($payload['data']['token_quota_remaining'] ?? 0));
                $this->line('  funding_status='.(string) ($payload['data']['funding_status'] ?? 'ready'));
                if (! empty($payload['data']['funding_diagnostic_id'])) {
                    $this->line('  funding_diagnostic_id='.(string) $payload['data']['funding_diagnostic_id']);
                }
            }
        } catch (\Throwable $exception) {
            $failed = true;
            $this->error('API key details: FAILED');
            $this->line('  '.get_class($exception).': '.\Illuminate\Support\Str::limit($exception->getMessage(), 1200));
        }
    }

    return $failed ? 1 : 0;
})->purpose('Diagnose Playground quota and one customer API-key details path without exposing secrets');
