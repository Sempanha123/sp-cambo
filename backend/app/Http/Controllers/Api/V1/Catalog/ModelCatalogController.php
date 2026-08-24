<?php

namespace App\Http\Controllers\Api\V1\Catalog;

use App\Http\Controllers\Controller;
use App\Models\ModelAlias;
use App\Models\ModelPricing;
use Illuminate\Http\JsonResponse;

class ModelCatalogController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $aliases = ModelAlias::query()->published()->with(['model', 'pricing'])->orderBy('public_alias')->get();

        return response()->json(['data' => $aliases->map(fn (ModelAlias $alias) => [
            'public_alias' => $alias->public_alias,
            'display_name' => $alias->display_name,
            'family' => $alias->model->family,
            'family_label' => $alias->model->family_label,
            'description' => $alias->description,
            'capabilities' => $alias->capabilities,
            'credit_pricing' => $alias->pricing ? $this->pricing($alias->pricing) : null,
            'limits' => $alias->limits,
            'status' => $alias->status,
        ])->values()]);
    }

    private function pricing(ModelPricing $pricing): array
    {
        $money = fn (?int $minor) => $minor === null ? null : [
            'minor' => (string) $minor,
            'currency' => $pricing->currency,
            'exponent' => $pricing->exponent,
        ];

        return [
            'input_per_million' => $money($pricing->input_per_million_minor),
            'output_per_million' => $money($pricing->output_per_million_minor),
            'cache_read_per_million' => $money($pricing->cache_read_per_million_minor),
            'cache_write_per_million' => $money($pricing->cache_write_per_million_minor),
            'reasoning_per_million' => $money($pricing->reasoning_per_million_minor),
        ];
    }
}
