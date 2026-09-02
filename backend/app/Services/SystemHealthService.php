<?php

namespace App\Services;

use App\Models\Provider;
use App\Models\SystemHeartbeat;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;

class SystemHealthService
{
    /** @return array{updated_at: string, overall: string, components: array<int, array<string, mixed>>} */
    public function measure(): array
    {
        $components = [[
            'key' => 'control_plane',
            'label' => 'Control plane',
            'status' => 'operational',
            'detail' => 'Release '.(string) config('app.release', 'development'),
        ]];

        try {
            DB::select('SELECT 1');
            $components[] = ['key' => 'database', 'label' => 'Database', 'status' => 'operational', 'detail' => null];
        } catch (Throwable) {
            $components[] = ['key' => 'database', 'label' => 'Database', 'status' => 'outage', 'detail' => 'Database health check failed.'];
        }

        try {
            $failedJobs = DB::table('failed_jobs')->count();
            $oldestQueuedAt = DB::table('jobs')->min('created_at');
            $queueLag = $oldestQueuedAt ? max(0, now()->timestamp - (int) $oldestQueuedAt) : 0;
            $components[] = [
                'key' => 'queue',
                'label' => 'Queue',
                'status' => $failedJobs > 0 || $queueLag > 300 ? 'degraded' : 'operational',
                'detail' => $failedJobs > 0 ? "{$failedJobs} failed job(s) require review." : null,
                'lag_seconds' => $queueLag,
            ];
        } catch (Throwable) {
            $components[] = ['key' => 'queue', 'label' => 'Queue', 'status' => 'outage', 'detail' => 'Queue health check failed.', 'lag_seconds' => null];
        }

        try {
            $heartbeat = SystemHeartbeat::query()->find('scheduler');
            $schedulerHealthy = $heartbeat?->recorded_at->greaterThan(now()->subMinutes(3)) ?? false;
            $components[] = [
                'key' => 'scheduler',
                'label' => 'Scheduler',
                'status' => $schedulerHealthy ? 'operational' : 'degraded',
                'detail' => $schedulerHealthy ? null : 'No scheduler heartbeat in the last three minutes.',
                'last_heartbeat_at' => $heartbeat?->recorded_at->toAtomString(),
            ];
        } catch (Throwable) {
            $components[] = ['key' => 'scheduler', 'label' => 'Scheduler', 'status' => 'outage', 'detail' => 'Scheduler health check failed.', 'last_heartbeat_at' => null];
        }

        $gatewayBase = rtrim((string) config('services.spcambo.gateway_base_url'), '/');
        $components[] = $this->httpHealth('gateway', 'Inference gateway', $gatewayBase.'/health');

        try {
            $provider = Provider::query()
                ->with('activeConnectionRevision')
                ->where('enabled', true)
                ->whereNotNull('active_connection_revision_id')
                ->get()
                ->first(fn (Provider $candidate): bool => $candidate->activeConnectionRevision?->isRouteReady() ?? false);

            $components[] = $provider
                ? [
                    'key' => 'omniroute',
                    'label' => 'Active provider route',
                    'status' => 'operational',
                    'detail' => $provider->name.' · '.$provider->activeConnectionRevision->route_version,
                ]
                : [
                    'key' => 'omniroute',
                    'label' => 'Active provider route',
                    'status' => 'degraded',
                    'detail' => 'No enabled provider has an active READY revision.',
                ];
        } catch (Throwable) {
            $components[] = ['key' => 'omniroute', 'label' => 'Active provider route', 'status' => 'outage', 'detail' => 'Provider route health could not be read.'];
        }

        $khqrUrl = $this->healthUrlFromServiceUrl((string) config('services.bakong.khqr_generator_url'));
        $components[] = $khqrUrl !== null
            ? $this->httpHealth('khqr', 'KHQR generator', $khqrUrl)
            : ['key' => 'khqr', 'label' => 'KHQR generator', 'status' => 'degraded', 'detail' => 'KHQR generator URL is not configured.'];

        $configuredBakongTokens = app(\App\Services\Payments\BakongTokenPool::class)->hasConfiguredTokens();
        $bakongReady = trim((string) config('services.bakong.base_url')) !== ''
            && $configuredBakongTokens
            && trim((string) config('services.bakong.account_id')) !== ''
            && trim((string) config('services.bakong.merchant_name')) !== '';
        $components[] = [
            'key' => 'bakong',
            'label' => 'Bakong verification',
            'status' => $bakongReady ? 'operational' : 'degraded',
            'detail' => $bakongReady
                ? 'Server-side Bakong verification credentials are configured. Transaction verification is checked during payment acceptance.'
                : 'Bakong server-side verification is not fully configured.',
        ];

        $components[] = $this->telegramTokenHealth('telegram_storefront', 'Telegram Store Bot', (string) config('services.telegram.storefront_bot_token'), 'Customer Store Bot token is not configured.');

        return [
            'updated_at' => now()->toAtomString(),
            'overall' => $this->overall($components),
            'components' => $components,
        ];
    }

    /** @return array{updated_at: string, overall: string, components: array<int, array<string, string|null>>} */
    public function publicStatus(): array
    {
        $health = $this->measure();
        $byKey = collect($health['components'])->keyBy('key');
        $controlStatus = $this->componentStatus(collect(['control_plane', 'database', 'queue', 'scheduler'])->map(fn (string $key): array => $byKey->get($key))->all());
        $inferenceStatus = $this->componentStatus(collect(['gateway', 'omniroute'])->map(fn (string $key): array => $byKey->get($key))->all());
        $paymentStatus = $this->componentStatus(collect(['khqr', 'bakong'])->map(fn (string $key): array => $byKey->get($key))->all());

        $publicComponents = [
            $this->publicComponent('control_plane', 'Control plane', $controlStatus),
            $this->publicComponent('inference_api', 'Inference API', $inferenceStatus),
            $this->publicComponent('payments', 'Payments', $paymentStatus),
        ];

        return [
            'updated_at' => $health['updated_at'],
            // Public overall must be derived only from the customer-safe groups
            // returned below. Hidden operator-only dependencies (for example a
            // Telegram probe) must not make /api/v1/status say outage while all
            // visible public components report only degraded/operational.
            'overall' => $this->overall($publicComponents),
            'components' => $publicComponents,
        ];
    }

    /** @return array<string,mixed> */
    private function httpHealth(string $key, string $label, string $url): array
    {
        if ($url === '' || ! preg_match('#^https?://#i', $url)) {
            return ['key' => $key, 'label' => $label, 'status' => 'degraded', 'detail' => 'Health URL is not configured.'];
        }

        try {
            $response = Http::connectTimeout(1.5)->timeout(2.5)->acceptJson()->get($url);
            $ok = $response->successful();

            return [
                'key' => $key,
                'label' => $label,
                'status' => $ok ? 'operational' : 'degraded',
                'detail' => $ok ? null : 'Dependency returned HTTP '.$response->status().'.',
            ];
        } catch (Throwable) {
            return ['key' => $key, 'label' => $label, 'status' => 'outage', 'detail' => 'Dependency health request failed.'];
        }
    }

    /** @return array<string,mixed> */
    private function telegramTokenHealth(string $key, string $label, string $token, string $missingDetail): array
    {
        $token = trim($token);
        if ($token === '') {
            return ['key' => $key, 'label' => $label, 'status' => 'degraded', 'detail' => $missingDetail];
        }

        try {
            $response = Http::connectTimeout(1.5)->timeout(2.5)->acceptJson()->get('https://api.telegram.org/bot'.$token.'/getMe');
            $ok = $response->successful() && $response->json('ok') === true;

            return [
                'key' => $key,
                'label' => $label,
                'status' => $ok ? 'operational' : 'degraded',
                'detail' => $ok ? null : 'Telegram authentication probe failed.',
            ];
        } catch (Throwable) {
            return ['key' => $key, 'label' => $label, 'status' => 'outage', 'detail' => 'Telegram health request failed.'];
        }
    }

    private function healthUrlFromServiceUrl(string $serviceUrl): ?string
    {
        if ($serviceUrl === '' || ! preg_match('#^https?://#i', $serviceUrl)) {
            return null;
        }

        $parts = parse_url($serviceUrl);
        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $origin = $parts['scheme'].'://'.$parts['host'];
        if (isset($parts['port'])) {
            $origin .= ':'.$parts['port'];
        }

        return $origin.'/health';
    }

    /** @param array<int, array<string, mixed>> $components */
    private function overall(array $components): string
    {
        $status = $this->componentStatus($components);
        return $status === 'maintenance' ? 'degraded' : $status;
    }

    /** @param array<int, array<string, mixed>> $components */
    private function componentStatus(array $components): string
    {
        foreach (['outage', 'degraded', 'maintenance'] as $status) {
            if (collect($components)->contains(fn (array $component): bool => ($component['status'] ?? 'outage') === $status)) {
                return $status;
            }
        }

        return 'operational';
    }

    /** @return array{key: string, label: string, status: string, detail: string|null} */
    private function publicComponent(string $key, string $label, string $status): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'status' => $status,
            'detail' => match ($status) {
                'outage' => 'This service is currently unavailable.',
                'degraded' => 'This service is currently degraded.',
                'maintenance' => 'Health is not currently measured.',
                default => null,
            },
        ];
    }
}
