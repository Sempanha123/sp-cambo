<?php

namespace App\Services;

use App\Models\SystemHeartbeat;
use Illuminate\Support\Facades\DB;
use Throwable;

class SystemHealthService
{
    /** @return array{updated_at: string, overall: string, components: array<int, array<string, mixed>>} */
    public function measure(): array
    {
        $components = [['key' => 'control_plane', 'label' => 'Control plane', 'status' => 'operational', 'detail' => null]];

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
            $components[] = ['key' => 'queue', 'label' => 'Queue', 'status' => $failedJobs > 0 || $queueLag > 300 ? 'degraded' : 'operational', 'detail' => $failedJobs > 0 ? "{$failedJobs} failed job(s) require review." : null, 'lag_seconds' => $queueLag];
        } catch (Throwable) {
            $components[] = ['key' => 'queue', 'label' => 'Queue', 'status' => 'outage', 'detail' => 'Queue health check failed.', 'lag_seconds' => null];
        }

        try {
            $heartbeat = SystemHeartbeat::query()->find('scheduler');
            $schedulerHealthy = $heartbeat?->recorded_at->greaterThan(now()->subMinutes(3)) ?? false;
            $components[] = ['key' => 'scheduler', 'label' => 'Scheduler', 'status' => $schedulerHealthy ? 'operational' : 'degraded', 'detail' => $schedulerHealthy ? null : 'No recent scheduler heartbeat.', 'last_heartbeat_at' => $heartbeat?->recorded_at->toAtomString()];
        } catch (Throwable) {
            $components[] = ['key' => 'scheduler', 'label' => 'Scheduler', 'status' => 'outage', 'detail' => 'Scheduler health check failed.', 'last_heartbeat_at' => null];
        }

        foreach ([['gateway', 'Inference gateway'], ['omniroute', 'Inference routing'], ['bakong', 'Payment verification']] as [$key, $label]) {
            $components[] = ['key' => $key, 'label' => $label, 'status' => 'maintenance', 'detail' => 'Connectivity is not currently measured.'];
        }

        return ['updated_at' => now()->toAtomString(), 'overall' => $this->overall($components), 'components' => $components];
    }

    /** @return array{updated_at: string, overall: string, components: array<int, array<string, string|null>>} */
    public function publicStatus(): array
    {
        $health = $this->measure();
        $byKey = collect($health['components'])->keyBy('key');
        $controlStatus = $this->componentStatus(collect(['control_plane', 'database', 'queue', 'scheduler'])->map(fn (string $key): array => $byKey->get($key))->all());
        $inferenceStatus = $this->componentStatus(collect(['gateway', 'omniroute'])->map(fn (string $key): array => $byKey->get($key))->all());
        $paymentStatus = $byKey->get('bakong')['status'];

        return [
            'updated_at' => $health['updated_at'],
            'overall' => $health['overall'],
            'components' => [
                $this->publicComponent('control_plane', 'Control plane', $controlStatus),
                $this->publicComponent('inference_api', 'Inference API', $inferenceStatus),
                $this->publicComponent('payments', 'Payments', $paymentStatus),
            ],
        ];
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
            if (collect($components)->contains(fn (array $component): bool => $component['status'] === $status)) {
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
