<?php

namespace App\Services;

use App\Models\EntitlementLot;
use App\Models\FulfillmentClaim;
use App\Models\OrderItem;
use App\Models\PlaygroundChat;
use App\Models\PlaygroundSetting;
use App\Models\RedeemCode;
use App\Models\ReferralSetting;
use App\Models\ReferralRegistrationReward;

class ModelAliasReferenceService
{
    /**
     * Public aliases are editable catalog identifiers, but several historical
     * access records intentionally store the alias string rather than the alias
     * FK. Keep those live references usable when an admin renames a public alias.
     */
    public function rename(string $from, string $to): void
    {
        $from = trim($from);
        $to = trim($to);

        if ($from === '' || $to === '' || $from === $to) {
            return;
        }

        PlaygroundSetting::query()->each(function (PlaygroundSetting $setting) use ($from, $to): void {
            $allowed = $this->replaceList($setting->allowed_model_aliases ?? [], $from, $to);
            $default = $setting->default_model_alias === $from ? $to : $setting->default_model_alias;

            if ($allowed !== array_values($setting->allowed_model_aliases ?? []) || $default !== $setting->default_model_alias) {
                $setting->forceFill([
                    'allowed_model_aliases' => $allowed,
                    'default_model_alias' => $default,
                ])->save();
            }
        });

        EntitlementLot::query()
            ->whereJsonContains('allowed_model_aliases', $from)
            ->chunkById(200, function ($lots) use ($from, $to): void {
                foreach ($lots as $lot) {
                    $lot->forceFill(['allowed_model_aliases' => $this->replaceList($lot->allowed_model_aliases ?? [], $from, $to)])->save();
                }
            });

        RedeemCode::query()
            ->whereJsonContains('allowed_model_aliases', $from)
            ->chunkById(200, function ($codes) use ($from, $to): void {
                foreach ($codes as $code) {
                    $code->forceFill(['allowed_model_aliases' => $this->replaceList($code->allowed_model_aliases ?? [], $from, $to)])->save();
                }
            });

        FulfillmentClaim::query()
            ->whereJsonContains('claim_snapshot->allowed_model_aliases', $from)
            ->chunkById(200, function ($claims) use ($from, $to): void {
                foreach ($claims as $claim) {
                    $snapshot = is_array($claim->claim_snapshot) ? $claim->claim_snapshot : [];
                    $snapshot['allowed_model_aliases'] = $this->replaceList($snapshot['allowed_model_aliases'] ?? [], $from, $to);
                    $claim->forceFill(['claim_snapshot' => $snapshot])->save();
                }
            });

        OrderItem::query()
            ->whereJsonContains('package_snapshot->allowed_model_aliases', $from)
            ->chunkById(200, function ($items) use ($from, $to): void {
                foreach ($items as $item) {
                    $snapshot = is_array($item->package_snapshot) ? $item->package_snapshot : [];
                    $snapshot['allowed_model_aliases'] = $this->replaceList($snapshot['allowed_model_aliases'] ?? [], $from, $to);
                    $item->forceFill(['package_snapshot' => $snapshot])->save();
                }
            });


        ReferralSetting::query()->each(function (ReferralSetting $setting) use ($from, $to): void {
            $aliases = $this->replaceList($setting->registration_reward_model_aliases ?? [], $from, $to);
            if ($aliases !== array_values($setting->registration_reward_model_aliases ?? [])) {
                $setting->forceFill(['registration_reward_model_aliases' => $aliases])->save();
            }
        });


        ReferralRegistrationReward::query()
            ->whereJsonContains('allowed_model_aliases', $from)
            ->chunkById(200, function ($rewards) use ($from, $to): void {
                foreach ($rewards as $reward) {
                    $reward->forceFill(['allowed_model_aliases' => $this->replaceList($reward->allowed_model_aliases ?? [], $from, $to)])->save();
                }
            });

        PlaygroundChat::query()->where('model_alias', $from)->update(['model_alias' => $to]);
    }

    /** @param array<int,mixed> $values @return array<int,string> */
    private function replaceList(array $values, string $from, string $to): array
    {
        return collect($values)
            ->filter(static fn ($value): bool => is_string($value) && trim($value) !== '')
            ->map(static fn (string $value): string => $value === $from ? $to : $value)
            ->unique()
            ->values()
            ->all();
    }
}
