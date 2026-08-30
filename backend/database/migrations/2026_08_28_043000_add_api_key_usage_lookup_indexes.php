<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->addIndexSafely('api_request_logs', ['user_id', 'api_key_id', 'started_at'], 'request_logs_user_key_started');
        $this->addIndexSafely('usage_records', ['user_id', 'api_key_id', 'settled_at'], 'usage_records_user_key_settled');
    }

    public function down(): void
    {
        $this->dropIndexSafely('api_request_logs', 'request_logs_user_key_started');
        $this->dropIndexSafely('usage_records', 'usage_records_user_key_settled');
    }

    /** @param array<int,string> $columns */
    private function addIndexSafely(string $table, array $columns, string $name): void
    {
        try {
            Schema::table($table, function (Blueprint $blueprint) use ($columns, $name): void {
                $blueprint->index($columns, $name);
            });
        } catch (QueryException $exception) {
            $message = strtolower($exception->getMessage());
            if (! str_contains($message, 'duplicate key name') && ! str_contains($message, 'already exists')) {
                throw $exception;
            }
        }
    }

    private function dropIndexSafely(string $table, string $name): void
    {
        try {
            Schema::table($table, function (Blueprint $blueprint) use ($name): void {
                $blueprint->dropIndex($name);
            });
        } catch (QueryException $exception) {
            $message = strtolower($exception->getMessage());
            if (! str_contains($message, 'does not exist') && ! str_contains($message, 'no such index') && ! str_contains($message, 'check that column/key exists')) {
                throw $exception;
            }
        }
    }
};
