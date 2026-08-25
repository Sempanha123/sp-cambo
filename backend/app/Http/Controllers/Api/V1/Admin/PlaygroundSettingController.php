<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\ModelAlias;
use App\Models\PlaygroundSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PlaygroundSettingController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json(['data' => $this->resource(PlaygroundSetting::current())]);
    }

    public function update(Request $request): JsonResponse
    {
        $input = $request->validate([
            'enabled' => ['required', 'boolean'],
            'daily_token_quota' => ['required', 'integer', 'min:0', 'max:1000000000'],
            'max_output_tokens' => ['required', 'integer', 'min:1', 'max:65536'],
            'allowed_model_aliases' => ['required', 'array', 'max:100'],
            'allowed_model_aliases.*' => ['string', 'max:100', Rule::exists('model_aliases', 'public_alias')],
            'gateway_base_url' => ['nullable', 'string', 'max:512', 'url:http,https'],
            'default_model_alias' => ['nullable', 'string', 'max:100', Rule::exists('model_aliases', 'public_alias')],
            'allow_model_switching' => ['required', 'boolean'],
        ]);

        // Only published aliases may be advertised as free Playground models.
        $requested = array_values(array_unique($input['allowed_model_aliases']));
        $published = ModelAlias::query()->published()->whereIn('public_alias', $requested)->pluck('public_alias')->all();
        if (count($published) !== count($requested)) {
            return response()->json([
                'message' => 'Every free Playground alias must currently be published and customer visible.',
                'code' => 'invalid_playground_model',
            ], 422);
        }

        $defaultAlias = filled($input['default_model_alias'] ?? null)
            ? trim((string) $input['default_model_alias'])
            : null;
        if ($defaultAlias !== null && ! in_array($defaultAlias, $published, true)) {
            return response()->json([
                'message' => 'The default Playground model must be one of the published free Playground aliases.',
                'code' => 'invalid_playground_default_model',
            ], 422);
        }

        $gatewayBaseUrl = filled($input['gateway_base_url'] ?? null)
            ? rtrim(trim((string) $input['gateway_base_url']), '/')
            : null;

        $setting = PlaygroundSetting::current();
        $setting->forceFill([
            'enabled' => (bool) $input['enabled'],
            'daily_token_quota' => (int) $input['daily_token_quota'],
            'max_output_tokens' => (int) $input['max_output_tokens'],
            'allowed_model_aliases' => $requested,
            'gateway_base_url' => $gatewayBaseUrl,
            'default_model_alias' => $defaultAlias,
            'allow_model_switching' => (bool) $input['allow_model_switching'],
        ])->save();

        return response()->json(['data' => $this->resource($setting->fresh())]);
    }

    private function resource(PlaygroundSetting $setting): array
    {
        return [
            'enabled' => (bool) $setting->enabled,
            'daily_token_quota' => (int) $setting->daily_token_quota,
            'max_output_tokens' => (int) $setting->max_output_tokens,
            'allowed_model_aliases' => array_values($setting->allowed_model_aliases ?? []),
            'gateway_base_url' => $setting->gateway_base_url,
            'default_model_alias' => $setting->default_model_alias,
            'allow_model_switching' => (bool) $setting->allow_model_switching,
        ];
    }
}
