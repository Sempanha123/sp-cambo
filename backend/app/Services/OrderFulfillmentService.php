<?php

namespace App\Services;

use App\Models\Order;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class OrderFulfillmentService
{
    public function __construct(
        private readonly EntitlementService $entitlements,
        private readonly FulfillmentClaimService $claims,
        private readonly TelegramPurchaseAlertService $purchaseAlerts,
        private readonly PackageStockService $stock,
        private readonly ReferralService $referrals,
    ) {}

    public function fulfill(Order $order): Order
    {
        $fulfilled = DB::transaction(function () use ($order): Order {
            $locked = Order::query()->with('items')->lockForUpdate()->findOrFail($order->id);
            if ($locked->fulfilled_at !== null) {
                return $locked;
            }

            $locked->update(['status' => 'PAID']);
            $locked = $this->stock->consumeForFulfillment($locked);
            $activated = now();
            $user = $locked->user()->firstOrFail();
            $tenant = $locked->tenant()->first();
            if (! $tenant) {
                $tenant = $user->tenant()->firstOrFail();
                $locked->forceFill(['tenant_id' => $tenant->id])->save();
            }

            /*
             * One package catalog for everyone:
             *
             * - normal customer -> normal personal entitlement
             * - ACTIVE reseller -> reseller inventory
             *
             * The reseller must have both reseller.manage and an ACTIVE
             * reseller_profile. This keeps a pending/disabled reseller from
             * receiving stock that its allocation API cannot use.
             */
            $isResellerBuyer = $user->hasPermission('reseller.manage')
                && DB::table('reseller_profiles')
                    ->where('user_id', $user->id)
                    ->where('status', 'ACTIVE')
                    ->exists();

            $claimsByItem = [];

            foreach ($locked->items as $item) {
                $snapshot = $item->package_snapshot;
                $aliases = array_values(array_filter(
                    array_unique($snapshot['allowed_model_aliases'] ?? []),
                    'is_string'
                ));

                $isResellerStock = $isResellerBuyer;
                $claim = null;

                // Normal customers keep the existing claim/access flow.
                // Reseller purchases bypass personal API-key allocation entirely.
                if ($aliases !== [] && ! $isResellerStock) {
                    $claim = $this->claims->create(
                        $tenant,
                        $item,
                        "order:{$locked->id}:item:{$item->id}:claim"
                    )['claim'];

                    $claimsByItem[(int) $item->id] = $claim;
                }

                for ($index = 0; $index < $item->quantity; $index++) {
                    $this->entitlements->grant($user, [
                        'source_type' => $isResellerStock
                            ? ResellerStockService::SOURCE_TYPE
                            : 'ORDER',
                        'source_id' => $locked->id,
                        'package_name' => $item->package_name,
                        'family_label' => $snapshot['family_label'],
                        'billing_mode' => $snapshot['billing_mode'],
                        'original_units' => (int) $snapshot['advertised_units'],
                        'unit_label' => $snapshot['unit_label'],
                        'currency' => $snapshot['currency'],
                        'currency_exponent' => $snapshot['currency_exponent'],
                        'allowed_model_aliases' => $aliases,
                        'billing_snapshot' => [
                            'limits' => $snapshot['limits'],
                            'billing_rules' => array_merge(
                                is_array($snapshot['billing_rules'] ?? null)
                                    ? $snapshot['billing_rules']
                                    : [],
                                $isResellerStock
                                    ? ['stock_kind' => ResellerStockService::SOURCE_TYPE]
                                    : []
                            ),
                        ],
                        'activated_at' => $activated,
                        'expires_at' => $activated->copy()->addSeconds($snapshot['duration_seconds']),
                        'access_scope' => $isResellerStock
                            ? ResellerStockService::ACCESS_SCOPE
                            : ($claim ? 'UNASSIGNED' : 'ACCOUNT'),
                        'bound_api_key_id' => null,
                        'fulfillment_claim_id' => $claim?->id,
                    ], "order:{$locked->id}:item:{$item->id}:lot:{$index}");
                }
            }

            /*
             * Promotion bonuses stay personal. Only units actually bought from
             * the package become reseller inventory.
             */
            $promotion = is_array($locked->promotion_snapshot)
                ? $locked->promotion_snapshot
                : [];
            $bonusUnits = (int) ($promotion['bonus_units'] ?? 0);

            if ($bonusUnits > 0) {
                $item = $locked->items->firstOrFail();
                $snapshot = $item->package_snapshot;
                $claim = $claimsByItem[(int) $item->id] ?? null;

                $this->entitlements->grant($user, [
                    'source_type' => 'PROMOTION',
                    'source_id' => $locked->id,
                    'package_name' => ($promotion['label'] ?? 'Promotion').' bonus',
                    'family_label' => $snapshot['family_label'],
                    'billing_mode' => $snapshot['billing_mode'],
                    'original_units' => $bonusUnits,
                    'unit_label' => $snapshot['unit_label'],
                    'currency' => $snapshot['currency'],
                    'currency_exponent' => $snapshot['currency_exponent'],
                    'allowed_model_aliases' => $snapshot['allowed_model_aliases'],
                    'billing_snapshot' => [
                        'limits' => $snapshot['limits'],
                        'billing_rules' => $snapshot['billing_rules'] ?? null,
                    ],
                    'activated_at' => $activated,
                    'expires_at' => $activated->copy()->addSeconds($snapshot['duration_seconds']),
                    'access_scope' => $claim ? 'UNASSIGNED' : 'ACCOUNT',
                    'bound_api_key_id' => null,
                    'fulfillment_claim_id' => $claim?->id,
                ], "order:{$locked->id}:promotion:bonus");
            }

            $locked->update([
                'status' => 'FULFILLED',
                'fulfilled_at' => now(),
            ]);

            $fulfilled = $locked->fresh('items');
            $this->purchaseAlerts->orderFulfilled($fulfilled);

            return $fulfilled;
        });

        try {
            $this->referrals->rewardFulfilledOrder($fulfilled);
        } catch (\Throwable $exception) {
            Log::error('Referral reward processing failed after fulfillment.', [
                'order_id' => (string) $fulfilled->id,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);
        }

        return $fulfilled;
    }
}
