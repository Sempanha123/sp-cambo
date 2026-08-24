<?php

namespace App\Services;

use App\Models\Order;
use Illuminate\Support\Facades\DB;

class OrderFulfillmentService
{
    public function __construct(
        private readonly EntitlementService $entitlements,
        private readonly FulfillmentClaimService $claims
    ) {}

    public function fulfill(Order $order): Order
    {
        return DB::transaction(function () use ($order): Order {
            $locked = Order::query()->with('items')->lockForUpdate()->findOrFail($order->id);
            if ($locked->fulfilled_at !== null) {
                return $locked;
            }
            $locked->update(['status' => 'PAID']);
            $activated = now();
            $user = $locked->user()->firstOrFail();
            $tenant = $locked->tenant()->first();

            if (! $tenant) {
                $tenant = $user->tenant()->firstOrFail();
                $locked->forceFill(['tenant_id' => $tenant->id])->save();
            }

            foreach ($locked->items as $item) {
                $snapshot = $item->package_snapshot;
                for ($index = 0; $index < $item->quantity; $index++) {
                    $this->entitlements->grant($user, ['source_type' => 'ORDER', 'source_id' => $locked->id, 'package_name' => $item->package_name, 'family_label' => $snapshot['family_label'], 'billing_mode' => $snapshot['billing_mode'], 'original_units' => (int) $snapshot['advertised_units'], 'unit_label' => $snapshot['unit_label'], 'currency' => $snapshot['currency'], 'currency_exponent' => $snapshot['currency_exponent'], 'allowed_model_aliases' => $snapshot['allowed_model_aliases'], 'billing_snapshot' => ['limits' => $snapshot['limits'], 'billing_rules' => $snapshot['billing_rules'] ?? null], 'activated_at' => $activated, 'expires_at' => $activated->copy()->addSeconds($snapshot['duration_seconds'])], "order:{$locked->id}:item:{$item->id}:lot:{$index}");
                }

                // Create fulfillment claim for auto_creates_api_key packages
                if (($snapshot['auto_creates_api_key'] ?? false) === true) {
                    $claimResult = $this->claims->create($tenant, $item, "order:{$locked->id}:item:{$item->id}:claim");
                    if ($claimResult['claim']) {
                        $item->update(['fulfillment_claim_id' => $claimResult['claim']->id]);
                    }
                }
            }

            $promotion = is_array($locked->promotion_snapshot) ? $locked->promotion_snapshot : [];
            $bonusUnits = (int) ($promotion['bonus_units'] ?? 0);
            if ($bonusUnits > 0) {
                // Order creation currently has exactly one immutable package item.
                // The bonus inherits that snapshotted commercial scope/expiry and
                // never reads mutable promotion or package rows during fulfillment.
                $item = $locked->items->firstOrFail();
                $snapshot = $item->package_snapshot;
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
                    'billing_snapshot' => ['limits' => $snapshot['limits'], 'billing_rules' => $snapshot['billing_rules'] ?? null],
                    'activated_at' => $activated,
                    'expires_at' => $activated->copy()->addSeconds($snapshot['duration_seconds']),
                ], "order:{$locked->id}:promotion:bonus");
            }

            $locked->update(['status' => 'FULFILLED', 'fulfilled_at' => now()]);

            return $locked->fresh('items');
        });
    }
}
