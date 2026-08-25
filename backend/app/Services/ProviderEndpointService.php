<?php

namespace App\Services;

use App\Models\ProviderConnectionRevision;

class ProviderEndpointService
{
    /** @return list<array{url: string, kind: 'health'|'models'}> */
    public function probeCandidates(ProviderConnectionRevision $revision): array
    {
        $models = array_map(
            static fn (string $url): array => ['url' => $url, 'kind' => 'models'],
            $this->modelCatalogUrls($revision)
        );

        return $this->uniqueCandidates([
            ['url' => $this->originRoot($revision).'/health', 'kind' => 'health'],
            ...$models,
        ]);
    }

    /** @return list<string> */
    public function modelCatalogUrls(ProviderConnectionRevision $revision): array
    {
        $base = rtrim($revision->origin, '/');
        $urls = str_ends_with(strtolower($base), '/v1')
            ? [$base.'/models', substr($base, 0, -3).'/models']
            : [$base.'/v1/models', $base.'/models'];

        return array_values(array_unique($urls));
    }

    private function originRoot(ProviderConnectionRevision $revision): string
    {
        $base = rtrim($revision->origin, '/');

        return str_ends_with(strtolower($base), '/v1')
            ? substr($base, 0, -3)
            : $base;
    }

    /**
     * @param  list<array{url: string, kind: 'health'|'models'}>  $candidates
     * @return list<array{url: string, kind: 'health'|'models'}>
     */
    private function uniqueCandidates(array $candidates): array
    {
        $seen = [];

        return array_values(array_filter($candidates, static function (array $candidate) use (&$seen): bool {
            if (isset($seen[$candidate['url']])) {
                return false;
            }

            $seen[$candidate['url']] = true;

            return true;
        }));
    }
}
