<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\SystemHealthService;
use Illuminate\Http\JsonResponse;

class SystemHealthController extends Controller
{
    public function __invoke(SystemHealthService $health): JsonResponse
    {
        return response()->json(['data' => $health->measure()]);
    }
}
