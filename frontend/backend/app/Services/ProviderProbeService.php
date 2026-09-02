<?php

namespace App\Services;

use App\Models\ProviderConnectionRevision;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class ProviderProbeService
{
    public function __construct(private readonly ProviderEndpointService $endpoints) {}

    /**
     * Probe cheap health/catalog endpoints first, then prove every inference
     * protocol the exact custom OmniRoute model accepts. The first successful
     * inference protocol is exposed as endpoint_kind for backwards compatibility,
     * while endpoint_kinds lets the sell catalog publish all verified protocols.
     *
     * Chat Completions is intentionally probed first because OmniRoute combos may
     * expose a Claude-compatible adapter and an OpenAI-compatible adapter at the
     * same time. SP Cambo's hosted Playground prefers Chat Completions when it is
     * verified because it gives the most consistent streaming/usage contract.
     *
     * @return array{success:bool,attempts:list<array{kind:string,status:int|null}>,endpoint_kind:string|null,endpoint_kinds:list<string>}
     */
    public function probe(ProviderConnectionRevision $revision, ?string $internalModel = null): array
    {
        $attempts = [];
        $headers = [
            'Authorization' => 'Bearer '.$revision->credential,
            'x-api-key' => $revision->credential,
        ];
        $timeout = max(1, (int) ceil($revision->timeout_ms / 1000));
        $model = trim((string) $internalModel);

        foreach ($this->endpoints->probeCandidates($revision) as $candidate) {
            try {
                $response = Http::timeout($timeout)
                    ->acceptJson()
                    ->withHeaders($headers)
                    ->get($candidate['url']);
                $attempts[] = ['kind' => $candidate['kind'], 'status' => $response->status()];

                if (! $response->successful()) {
                    continue;
                }

                if ($model === '') {
                    return [
                        'success' => true,
                        'attempts' => $attempts,
                        'endpoint_kind' => $candidate['kind'],
                        'endpoint_kinds' => [],
                    ];
                }

                if ($candidate['kind'] === 'models' && $this->catalogContainsModel($response, $model)) {
                    // Catalog presence proves the custom model exists, but it does
                    // not prove an inference protocol. Keep probing below.
                    continue;
                }
            } catch (\Throwable) {
                $attempts[] = ['kind' => $candidate['kind'], 'status' => null];
            }
        }

        if ($model === '') {
            return ['success' => false, 'attempts' => $attempts, 'endpoint_kind' => null, 'endpoint_kinds' => []];
        }

        $successfulProtocols = [];
        foreach ($this->inferenceCandidates($revision, $model) as $candidate) {
            try {
                $request = Http::timeout($timeout)
                    ->acceptJson()
                    ->asJson()
                    ->withHeaders($headers + $candidate['headers']);
                /** @var Response $response */
                $response = $request->post($candidate['url'], $candidate['body']);
                $attempts[] = ['kind' => $candidate['kind'], 'status' => $response->status()];

                if ($response->successful()) {
                    $successfulProtocols[] = $candidate['kind'];
                }
            } catch (\Throwable) {
                $attempts[] = ['kind' => $candidate['kind'], 'status' => null];
            }
        }

        $successfulProtocols = array_values(array_unique($successfulProtocols));

        return [
            'success' => $successfulProtocols !== [],
            'attempts' => $attempts,
            'endpoint_kind' => $successfulProtocols[0] ?? null,
            'endpoint_kinds' => $successfulProtocols,
        ];
    }

    private function catalogContainsModel(Response $response, string $model): bool
    {
        $payload = $response->json();
        if (! is_array($payload)) {
            return false;
        }

        $candidates = [];
        $collect = static function ($value) use (&$candidates): void {
            if (is_string($value) && trim($value) !== '') {
                $candidates[] = trim($value);
            }
        };

        $rows = $payload['data'] ?? $payload['models'] ?? $payload;
        if (! is_array($rows)) {
            return false;
        }

        foreach ($rows as $row) {
            if (is_string($row)) {
                $collect($row);
                continue;
            }
            if (! is_array($row)) {
                continue;
            }
            foreach (['id', 'model', 'name', 'slug'] as $key) {
                if (array_key_exists($key, $row)) {
                    $collect($row[$key]);
                }
            }
        }

        return in_array($model, $candidates, true);
    }

    /** @return list<array{url:string,kind:string,headers:array<string,string>,body:array<string,mixed>}> */
    private function inferenceCandidates(ProviderConnectionRevision $revision, string $model): array
    {
        $root = rtrim($revision->origin, '/');
        if (str_ends_with(strtolower($root), '/v1')) {
            $root = substr($root, 0, -3);
        }

        return [
            [
                'url' => $root.'/v1/chat/completions',
                'kind' => 'chat_completions',
                'headers' => [],
                'body' => [
                    'model' => $model,
                    'messages' => [
                        ['role' => 'system', 'content' => 'You are a helpful assistant.'],
                        ['role' => 'user', 'content' => 'Reply only with OK.'],
                    ],
                    'stream' => false,
                    'temperature' => 1,
                    'max_tokens' => 8,
                    'top_p' => 1,
                    'presence_penalty' => 0,
                    'frequency_penalty' => 0,
                ],
            ],
            [
                'url' => $root.'/v1/messages',
                'kind' => 'messages',
                'headers' => ['anthropic-version' => '2023-06-01'],
                'body' => [
                    'model' => $model,
                    'max_tokens' => 8,
                    'messages' => [['role' => 'user', 'content' => 'Reply only with OK.']],
                ],
            ],
            [
                'url' => $root.'/v1/responses',
                'kind' => 'responses',
                'headers' => [],
                'body' => [
                    'model' => $model,
                    'max_output_tokens' => 8,
                    'input' => 'Reply only with OK.',
                ],
            ],
        ];
    }
}
