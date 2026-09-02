<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlaygroundSetting extends Model
{
    public $incrementing = false;

    protected $keyType = 'int';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'daily_token_quota' => 'integer',
            'max_output_tokens' => 'integer',
            'allowed_model_aliases' => 'array',
            'allow_model_switching' => 'boolean',
        ];
    }

    public static function current(): self
    {
        return static::query()->firstOrCreate(
            ['id' => 1],
            [
                'enabled' => true,
                'daily_token_quota' => max(0, (int) config('services.spcambo.playground_daily_token_quota', 20000)),
                'max_output_tokens' => 2048,
                'allowed_model_aliases' => [],
                'gateway_base_url' => null,
                'default_model_alias' => null,
                'allow_model_switching' => true,
            ]
        );
    }
}
