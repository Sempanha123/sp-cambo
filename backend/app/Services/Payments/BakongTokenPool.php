<?php

namespace App\Services\Payments;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

/**
 * Ordered, shared quota accounting for Bakong credentials approved for one
 * deployment. Token values never enter cache keys, logs, API responses, or DB.
 */
final class BakongTokenPool
{
    /** @return list<string> */
    public function configuredTokens(): array
    {
        $configured = config('services.bakong.tokens', []);
        $tokens = is_array($configured) ? $configured : [];

        $legacy = trim((string) config('services.bakong.token'));
        if ($legacy !== '') {
            array_unshift($tokens, $legacy);
        }

        return array_values(array_unique(array_filter(
            array_map(static fn ($token): string => trim((string) $token), $tokens),
            static fn (string $token): bool => $token !== ''
        )));
    }

    public function hasConfiguredTokens(): bool
    {
        return $this->configuredTokens() !== [];
    }

    /**
     * Reserve one request from the first credential with local daily capacity.
     * The shared-cache increment is atomic, so concurrent PHP-FPM and queue
     * workers cannot both claim request number 100.
     *
     * @return array{token:string,slot:int}|null
     */
    public function reserve(): ?array
    {
        $limit = $this->dailyLimit();
        $resetAt = $this->resetAt();

        foreach ($this->configuredTokens() as $index => $token) {
            $key = $this->quotaKey($token);
            Cache::add($key, 0, $resetAt);

            if ((int) Cache::get($key, 0) >= $limit) {
                continue;
            }

            $count = Cache::increment($key);
            if (is_bool($count) && $count) {
                $count = (int) Cache::get($key, 0);
            }

            if (! is_int($count)) {
                throw new RuntimeException('The shared cache could not reserve Bakong verification capacity.');
            }

            if ($count <= $limit) {
                return ['token' => $token, 'slot' => $index + 1];
            }
        }

        return null;
    }

    /** Mark a credential unavailable until the configured daily reset. */
    public function markDailyLimitReached(string $token): void
    {
        Cache::put($this->quotaKey($token), $this->dailyLimit(), $this->resetAt());
    }

    private function dailyLimit(): int
    {
        return max(1, (int) config('services.bakong.token_daily_limit', 100));
    }

    private function resetAt(): CarbonImmutable
    {
        return CarbonImmutable::now($this->quotaTimezone())
            ->addDay()
            ->startOfDay();
    }

    private function quotaKey(string $token): string
    {
        $key = (string) config('app.key', 'sp-cambo');
        $fingerprint = hash_hmac('sha256', $token, $key !== '' ? $key : 'sp-cambo');
        $day = CarbonImmutable::now($this->quotaTimezone())
            ->format('Y-m-d');

        return "bakong:verification:quota:{$day}:{$fingerprint}";
    }

    private function quotaTimezone(): string
    {
        $timezone = trim((string) config('services.bakong.quota_timezone', 'Asia/Phnom_Penh'));

        return $timezone !== '' ? $timezone : 'Asia/Phnom_Penh';
    }
}
