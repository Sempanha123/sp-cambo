<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Events\CustomerStateChanged;
use App\Http\Controllers\Controller;
use App\Models\ApiKey;
use App\Models\ApiRequestLog;
use App\Models\EntitlementLot;
use App\Models\Order;
use App\Models\PaymentAttempt;
use App\Models\Reservation;
use App\Models\TelegramAnnouncementDelivery;
use App\Models\TelegramPurchase;
use App\Models\User;
use App\Services\AuditService;
use App\Services\EntitlementService;
use App\Services\PaymentService;
use App\Services\ReservationService;
use App\Services\TelegramAnnouncementService;
use App\Services\TelegramCommerceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class OperationsController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json(['data' => [
            'release' => (string) config('app.release', 'development'),
            'updated_at' => now()->toAtomString(),
            'users' => User::query()->count(),
            'orders' => $this->statusCounts(Order::query()),
            'payments' => $this->statusCounts(PaymentAttempt::query()),
            'payments_recoverable' => PaymentAttempt::query()
                ->where('status', 'VERIFYING')
                ->where(fn ($query) => $query->whereNull('verification_lease_expires_at')->orWhere('verification_lease_expires_at', '<=', now()))
                ->count(),
            'telegram_purchases' => $this->statusCounts(TelegramPurchase::query()),
            'telegram_recoverable' => TelegramPurchase::query()
                ->whereNull('delivered_at')
                ->whereIn('status', ['AWAITING_PAYMENT', 'PAID', 'DELIVERY_FAILED'])
                ->where(fn ($query) => $query->whereNull('delivery_lease_expires_at')->orWhere('delivery_lease_expires_at', '<=', now()))
                ->count(),
            'telegram_announcement_failures' => TelegramAnnouncementDelivery::query()->where('status', 'FAILED')->count(),
            'api_keys' => [
                'total' => ApiKey::query()->count(),
                'active' => ApiKey::query()->where('status', 'ACTIVE')->whereNull('revoked_at')->count(),
            ],
            'entitlements' => [
                'active' => EntitlementLot::query()->where('status', 'ACTIVE')->count(),
                'expired' => EntitlementLot::query()->where('status', 'EXPIRED')->count(),
            ],
            'reservations' => [
                'active' => Reservation::query()->where('status', 'ACTIVE')->count(),
                'reconciliation_required' => Reservation::query()->where('status', 'RECONCILIATION_REQUIRED')->count(),
                'stale' => Reservation::query()->where('status', 'ACTIVE')->where('expires_at', '<=', now())->count(),
            ],
        ]]);
    }

    public function reconciliationReservations(Request $request): JsonResponse
    {
        $input = $request->validate(['limit' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $limit = (int) ($input['limit'] ?? 50);

        $items = Reservation::query()
            ->with(['user:id,name,email', 'apiKey:id,label,prefix,last_four'])
            ->where('status', 'RECONCILIATION_REQUIRED')
            ->orderBy('reconciliation_requested_at')
            ->orderBy('created_at')
            ->limit($limit)
            ->get()
            ->map(static fn (Reservation $reservation): array => [
                'id' => (string) $reservation->id,
                'user' => $reservation->user ? [
                    'id' => (string) $reservation->user->id,
                    'name' => (string) $reservation->user->name,
                    'email' => (string) $reservation->user->email,
                ] : null,
                'api_key' => $reservation->apiKey ? [
                    'id' => (string) $reservation->apiKey->id,
                    'label' => (string) $reservation->apiKey->label,
                    'masked' => $reservation->apiKey->prefix.'...'.$reservation->apiKey->last_four,
                ] : null,
                'public_model' => (string) $reservation->public_model_alias,
                'billing_mode' => (string) $reservation->billing_mode,
                'reserved_units' => (string) $reservation->reserved_units,
                'reason' => $reservation->reconciliation_reason,
                'requested_at' => $reservation->reconciliation_requested_at?->toAtomString(),
                'original_expires_at' => $reservation->expires_at?->toAtomString(),
                'created_at' => $reservation->created_at?->toAtomString(),
            ])->values();

        return response()->json(['data' => $items]);
    }

    public function releaseReconciliation(
        Request $request,
        Reservation $reservation,
        ReservationService $reservations,
        AuditService $audit,
    ): JsonResponse {
        $input = $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
            'confirmation' => ['required', Rule::in(['CONFIRMED NO UPSTREAM USAGE'])],
        ]);

        $released = DB::transaction(function () use ($request, $reservation, $reservations, $audit, $input): Reservation {
            $released = $reservations->releaseReconciliation($reservation, (string) $input['confirmation']);

            ApiRequestLog::query()
                ->where('reservation_id', $released->id)
                ->where('state', 'RECONCILING')
                ->update([
                    'state' => 'RELEASED',
                    'estimated_units' => null,
                    'finished_at' => now(),
                    'error_code' => 'operator_confirmed_no_upstream_usage',
                ]);

            $audit->record(
                $request->user(),
                'operations.reconciliation_released',
                'reservation',
                $released->id,
                trim((string) $input['reason']),
                [
                    'public_model' => $released->public_model_alias,
                    'billing_mode' => $released->billing_mode,
                    'reserved_units' => (string) $released->reserved_units,
                    'resolution' => 'confirmed_no_upstream_usage',
                ],
            );

            return $released;
        });

        CustomerStateChanged::dispatch((int) $released->user_id, 'api_request.failed', [
            'reservation_id' => $released->id,
            'public_model' => $released->public_model_alias,
            'state' => 'released',
            'error_code' => 'operator_confirmed_no_upstream_usage',
        ]);

        return response()->json(['data' => [
            'id' => (string) $released->id,
            'status' => (string) $released->status,
            'settled_units' => '0',
        ]]);
    }

    public function recover(
        Request $request,
        PaymentService $payments,
        TelegramCommerceService $telegram,
        ReservationService $reservations,
        EntitlementService $entitlements,
        TelegramAnnouncementService $announcements,
        AuditService $audit,
    ): JsonResponse {
        $input = $request->validate([
            'action' => ['required', Rule::in(['payments', 'telegram_purchases', 'reservations', 'entitlements', 'announcements'])],
            'batch' => ['nullable', 'integer', 'min:1', 'max:200'],
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
        ]);
        $batch = (int) ($input['batch'] ?? 25);

        $result = match ($input['action']) {
            'payments' => $payments->reconcilePending(min($batch, 4)),
            'telegram_purchases' => $telegram->reconcilePending(min($batch, 10)),
            'reservations' => ['recovered' => $reservations->recoverStale($batch)],
            'entitlements' => ['expired' => $entitlements->expireDue($batch)],
            'announcements' => $announcements->dispatchPending($batch),
        };

        $audit->record(
            $request->user(),
            'operations.recovery_run',
            'system',
            (string) $input['action'],
            trim((string) $input['reason']),
            ['action' => $input['action'], 'batch' => $batch, 'result' => $result],
        );

        return response()->json(['data' => ['action' => $input['action'], 'result' => $result]]);
    }

    public function verifyPayment(Request $request, PaymentAttempt $paymentAttempt, PaymentService $payments, AuditService $audit): JsonResponse
    {
        $input = $request->validate(['reason' => ['required', 'string', 'min:10', 'max:1000']]);
        $result = $payments->verify($paymentAttempt);
        $audit->record($request->user(), 'operations.payment_rechecked', 'payment_attempt', $paymentAttempt->id, trim($input['reason']), ['status' => $result->status]);

        return response()->json(['data' => ['id' => $result->id, 'status' => $result->status, 'order_id' => $result->order_id]]);
    }

    public function retryTelegramPurchase(Request $request, TelegramPurchase $telegramPurchase, TelegramCommerceService $telegram, AuditService $audit): JsonResponse
    {
        $input = $request->validate(['reason' => ['required', 'string', 'min:10', 'max:1000']]);
        $result = $telegram->reconcile($telegramPurchase);
        $audit->record($request->user(), 'operations.telegram_purchase_retried', 'telegram_purchase', $telegramPurchase->id, trim($input['reason']), ['status' => $result->status]);

        return response()->json(['data' => ['id' => $result->id, 'status' => $result->status, 'delivered_at' => $result->delivered_at?->toAtomString()]]);
    }

    private function statusCounts($query): array
    {
        return $query->selectRaw('status, COUNT(*) as aggregate')->groupBy('status')->pluck('aggregate', 'status')->map(fn ($value): int => (int) $value)->all();
    }
}
