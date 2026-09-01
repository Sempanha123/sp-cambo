<?php

namespace App\Http\Controllers\Api\V1\Internal;

use App\Http\Controllers\Controller;
use App\Models\Reservation;
use App\Services\ModelRoutePoolService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GatewayRouteController extends Controller
{
    public function reroute(
        Request $request,
        Reservation $reservation,
        ModelRoutePoolService $routes,
    ): JsonResponse {
        $input = $request->validate([
            'failure_code' => ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9._:-]+$/'],
            'upstream_status' => ['nullable', 'integer', 'between:400,599'],
        ]);

        $next = $routes->failover(
            $reservation,
            (string) $input['failure_code'],
            isset($input['upstream_status']) ? (int) $input['upstream_status'] : null,
        );

        return response()->json(['data' => [
            'internal_model' => (string) $next['model']->internal_model_id,
            'route_revision_id' => (string) $next['revision']->id,
            'route_version' => (int) $next['revision']->route_version,
            'upstream_origin' => rtrim((string) $next['revision']->origin, '/'),
            'upstream_credential' => (string) $next['revision']->credential,
            'upstream_timeout_ms' => (int) $next['revision']->timeout_ms,
        ]]);
    }

    public function success(
        Reservation $reservation,
        ModelRoutePoolService $routes,
    ): JsonResponse {
        $routes->markReservationRouteHealthy($reservation->fresh());

        return response()->json(['data' => [
            'reservation_id' => (string) $reservation->id,
            'route_healthy' => true,
        ]]);
    }
}
