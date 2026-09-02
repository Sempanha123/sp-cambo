<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\SystemHealthService;
use Illuminate\Http\JsonResponse;

class StatusController extends Controller
{
    public function __invoke(SystemHealthService $health): JsonResponse
    {
        return response()->json(['data' => $health->publicStatus()]);
    }
}
