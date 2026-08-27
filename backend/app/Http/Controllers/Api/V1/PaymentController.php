<?php

namespace App\Http\Controllers\Api\V1;

use App\Events\CustomerStateChanged;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\PaymentAttempt;
use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    public function show(Request $request, string $order): JsonResponse
    {
        $owned = Order::query()->where('user_id', $request->user()->id)->whereNull('customer_hidden_at')->findOrFail($order);
        $attempt = $owned->paymentAttempts()->latest()->firstOrFail();
        // A read must never cancel a verification worker that already owns a
        // live lease. Only a plain PENDING attempt is expired here. VERIFYING is
        // resolved by PaymentService/scheduler, which either settles it or restores
        // PENDING/EXPIRED after the Bakong lookup completes.
        if ($attempt->status === 'PENDING' && $attempt->expires_at->isPast()) {
            $attempt->update(['status' => 'EXPIRED']);
            CustomerStateChanged::dispatch((int) $owned->user_id, 'payment.updated', ['order_id' => $owned->id, 'payment_status' => 'EXPIRED', 'order_status' => $owned->status]);
        }

        return response()->json(['data' => $this->resource($attempt)]);
    }

    public function store(Request $request, string $order, PaymentService $payments): JsonResponse
    {
        $owned = Order::query()->where('user_id', $request->user()->id)->whereNull('customer_hidden_at')->findOrFail($order);
        $attempt = $payments->createAttempt($owned);
        CustomerStateChanged::dispatch((int) $owned->user_id, 'payment.updated', ['order_id' => $owned->id, 'payment_status' => $attempt->status, 'order_status' => $owned->status]);

        return response()->json(['data' => $this->resource($attempt)]);
    }

    public function autoCheck(Request $request, string $order, PaymentService $payments): JsonResponse
    {
        $owned = Order::query()->where('user_id', $request->user()->id)->whereNull('customer_hidden_at')->findOrFail($order);
        $attempt = $owned->paymentAttempts()->latest()->firstOrFail();

        $verified = $payments->verifyIfDue($attempt);
        CustomerStateChanged::dispatch((int) $owned->user_id, 'payment.updated', [
            'order_id' => $owned->id,
            'payment_status' => $verified->status,
            'order_status' => $verified->order?->status ?? $owned->fresh()->status,
        ]);

        return response()->json(['data' => $this->resource($verified)]);
    }

    public function verify(Request $request, string $order, PaymentService $payments): JsonResponse
    {
        $owned = Order::query()->where('user_id', $request->user()->id)->whereNull('customer_hidden_at')->findOrFail($order);
        $attempt = $owned->paymentAttempts()->latest()->firstOrFail();

        $verified = $payments->verify($attempt);
        CustomerStateChanged::dispatch((int) $owned->user_id, 'payment.updated', ['order_id' => $owned->id, 'payment_status' => $verified->status, 'order_status' => $verified->order?->status ?? $owned->fresh()->status]);

        return response()->json(['data' => $this->resource($verified)]);
    }

    private function resource(PaymentAttempt $attempt): array
    {
        return ['id' => $attempt->id, 'order_id' => $attempt->order_id, 'status' => $attempt->status, 'qr_payload' => $attempt->qr_payload, 'qr_image_url' => null, 'amount' => ['minor' => (string) $attempt->amount_minor, 'currency' => $attempt->currency, 'exponent' => (int) $attempt->currency_exponent], 'merchant_display_name' => (string) config('services.bakong.merchant_name'), 'expires_at' => $attempt->expires_at->toAtomString(), 'server_time' => now()->toAtomString(), 'last_checked_at' => $attempt->last_checked_at?->toAtomString()];
    }
}
