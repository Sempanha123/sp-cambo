<?php

use App\Models\Role;
use App\Models\SystemHeartbeat;
use App\Models\User;
use App\Services\AuditService;
use App\Services\EntitlementService;
use App\Services\PaymentService;
use App\Services\ReservationService;
use App\Services\TelegramCommerceService;
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
})->purpose('Release expired inference reservations safely');

Artisan::command('billing:expire-entitlements {--batch=100}', function (): int {
    $expired = app(EntitlementService::class)->expireDue((int) $this->option('batch'));
    $this->info("Expired {$expired} entitlement lot(s).");

    return 0;
})->purpose('Expire due entitlement lots atomically');

Artisan::command('payments:reconcile-pending {--batch=1}', function (): int {
    $result = app(PaymentService::class)->reconcilePending((int) $this->option('batch'));
    $this->info("Checked {$result['checked']} payment(s); {$result['failed']} failed.");

    return $result['failed'] === 0 ? 0 : 1;
})->purpose('Reconcile pending Bakong payment attempts within the API rate budget');


Artisan::command('telegram:reconcile-purchases {--batch=4}', function (): int {
    $result = app(TelegramCommerceService::class)->reconcilePending((int) $this->option('batch'));
    $this->info("Checked {$result['checked']} Telegram purchase(s); {$result['failed']} failed.");

    return $result['failed'] === 0 ? 0 : 1;
})->purpose('Verify Telegram-originated payments and deliver fulfilled API access');

Artisan::command('system:heartbeat', function (): int {
    SystemHeartbeat::query()->updateOrCreate(['component' => 'scheduler'], ['recorded_at' => now()]);

    return 0;
})->purpose('Record the scheduler heartbeat');

Schedule::command('billing:recover-stale-reservations')
    ->everyMinute()
    ->withoutOverlapping();

Schedule::command('billing:expire-entitlements')
    ->everyMinute()
    ->withoutOverlapping();

Schedule::command('payments:reconcile-pending --batch=1')
    ->everyFifteenMinutes()
    ->withoutOverlapping();


Schedule::command('telegram:reconcile-purchases --batch=4')
    ->everyMinute()
    ->withoutOverlapping();

Schedule::command('system:heartbeat')->everyMinute()->withoutOverlapping();
