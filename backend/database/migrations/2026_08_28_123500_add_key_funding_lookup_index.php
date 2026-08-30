<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        try {
            Schema::table('entitlement_lots', function (Blueprint $table): void {
                $table->index(['user_id', 'status', 'access_scope', 'bound_api_key_id', 'expires_at'], 'entitlement_user_key_funding_lookup');
            });
        } catch (QueryException $exception) {
            $message = strtolower($exception->getMessage());
            if (! str_contains($message, 'duplicate key name') && ! str_contains($message, 'already exists')) {
                throw $exception;
            }
        }
    }

    public function down(): void
    {
        try {
            Schema::table('entitlement_lots', fn (Blueprint $table) => $table->dropIndex('entitlement_user_key_funding_lookup'));
        } catch (QueryException $exception) {
            $message = strtolower($exception->getMessage());
            if (! str_contains($message, 'does not exist') && ! str_contains($message, 'no such index') && ! str_contains($message, 'check that column/key exists')) {
                throw $exception;
            }
        }
    }
};
