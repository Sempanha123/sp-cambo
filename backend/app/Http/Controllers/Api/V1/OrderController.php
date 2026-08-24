<?php

namespace App\Http\Controllers\Api\V1;

use App\Events\CustomerStateChanged;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\OrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $orders = Order::query()->where('user_id', $request->user()->id)->with('items')->latest()->get();

        return response()->json(['data' => $orders->map(fn (Order $order) => $this->resource($order))]);
    }

    public function store(Request $request, OrderService $orders): JsonResponse
    {
        $data = $request->validate(['package_slug' => ['required', 'string', 'max:100'], 'quantity' => ['sometimes', 'integer', 'between:1,100'], 'promotion_code' => ['nullable', 'string', 'max:50'], 'idempotency_key' => ['required', 'string', 'max:191', 'regex:/^[A-Za-z0-9][A-Za-z0-9._:-]*$/']]);
        $result = $orders->create($request->user(), $data['package_slug'], $data['quantity'] ?? 1, $data['promotion_code'] ?? null, $data['idempotency_key']);
        $order = $result['order'];

        if ($result['created']) {
            CustomerStateChanged::dispatch((int) $request->user()->id, 'order.created', ['order_id' => $order->id, 'status' => $order->status]);
        }

        return response()->json(['data' => $this->resource($order)], $result['created'] ? 201 : 200);
    }

    public function show(Request $request, string $order): JsonResponse
    {
        $model = Order::query()->where('user_id', $request->user()->id)->with('items')->findOrFail($order);

        return response()->json(['data' => $this->resource($model)]);
    }

    private function resource(Order $order): array
    {
        $money = fn (int $minor): array => ['minor' => (string) $minor, 'currency' => $order->currency, 'exponent' => (int) $order->currency_exponent];

        return ['id' => $order->id, 'reference' => $order->reference, 'status' => $order->status, 'created_at' => $order->created_at->toAtomString(), 'items' => $order->items->map(fn ($item) => [
            'package_slug' => $item->package_slug,
            'package_name' => $item->package_name,
            'quantity' => (int) $item->quantity,
            'unit_price' => $money((int) $item->unit_price_minor),
            'line_total' => $money((int) $item->line_total_minor),
            'api_key_activation_included' => (bool) (($item->package_snapshot['auto_creates_api_key'] ?? false)),
            'fulfillment_claim_id' => $item->fulfillment_claim_id,
        ])->values(), 'subtotal' => $money((int) $order->subtotal_minor), 'discount_total' => $money((int) $order->discount_total_minor), 'total' => $money((int) $order->total_minor), 'applied_promotion' => $order->promotion_snapshot ? ['code' => $order->promotion_snapshot['code'], 'label' => $order->promotion_snapshot['label']] : null, 'fulfilled_at' => $order->fulfilled_at?->toAtomString()];
    }
}
