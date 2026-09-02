<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\ModelAlias;
use App\Models\PlaygroundSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
            'allowed_model_aliases.*' => ['string', 'max:100'],
            'gateway_base_url' => ['nullable', 'string', 'max:512', 'url:http,https'],
            'default_model_alias' => ['nullable', 'string', 'max:100'],
            'allow_model_switching' => ['required', 'boolean'],
        ]);

        // Public aliases can be renamed from Providers. Older browser tabs or a
        // pre-Fix32 setting row may still submit the previous alias string. Drop
        // aliases that no longer exist, but still reject aliases that exist and
        // are intentionally unpublished so an admin cannot bypass publication.
        $requestedRaw = collect($input['allowed_model_aliases'])
            ->filter(static fn ($value): bool => is_string($value) && trim($value) !== '')
            ->map(static fn (string $value): string => strtolower(trim($value)))
            ->unique()
            ->values();

        $existing = ModelAlias::query()
            ->whereIn('public_alias', $requestedRaw->all())
            ->pluck('public_alias')
            ->all();
        $published = ModelAlias::query()
            ->published()
            ->whereIn('public_alias', $existing)
            ->pluck('public_alias')
            ->all();
        $unpublished = array_values(array_diff($existing, $published));
        if ($unpublished !== []) {
            return response()->json([
                'message' => 'One or more selected Playground aliases are not currently published: '.implode(', ', $unpublished),
                'code' => 'invalid_playground_model',
            ], 422);
        }
        $requested = array_values(array_filter($requestedRaw->all(), static fn (string $alias): bool => in_array($alias, $published, true)));

        $defaultAlias = filled($input['default_model_alias'] ?? null)
            ? strtolower(trim((string) $input['default_model_alias']))
            : null;
        if ($defaultAlias !== null && ! in_array($defaultAlias, $requested, true)) {
            // A renamed/deleted default alias from an older setting is stale, not
            // a reason to make the whole settings form unsaveable.
            $defaultAlias = $requested[0] ?? null;
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
        $configured = collect($setting->allowed_model_aliases ?? [])
            ->filter(static fn ($value): bool => is_string($value) && trim($value) !== '')
            ->map(static fn (string $value): string => strtolower(trim($value)))
            ->unique()
            ->values();
        $published = ModelAlias::query()
            ->published()
            ->whereIn('public_alias', $configured->all())
            ->pluck('public_alias')
            ->all();
        $allowed = array_values(array_filter($configured->all(), static fn (string $alias): bool => in_array($alias, $published, true)));
        $default = is_string($setting->default_model_alias) && in_array($setting->default_model_alias, $allowed, true)
            ? $setting->default_model_alias
            : ($allowed[0] ?? null);

        return [
            'enabled' => (bool) $setting->enabled,
            'daily_token_quota' => (int) $setting->daily_token_quota,
            'max_output_tokens' => (int) $setting->max_output_tokens,
            'allowed_model_aliases' => $allowed,
            'gateway_base_url' => $setting->gateway_base_url,
            'default_model_alias' => $default,
            'allow_model_switching' => (bool) $setting->allow_model_switching,
        ];
    }
}
