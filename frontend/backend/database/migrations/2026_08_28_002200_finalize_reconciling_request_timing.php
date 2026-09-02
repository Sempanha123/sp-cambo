<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('api_request_logs as logs')
            ->join('reservations as reservations', 'reservations.id', '=', 'logs.reservation_id')
            ->where('logs.state', 'RECONCILING')
            ->whereNull('logs.finished_at')
            ->whereNotNull('reservations.reconciliation_requested_at')
            ->select([
                'logs.id as log_id',
                'logs.started_at',
                'reservations.reconciliation_requested_at as reconciled_at',
            ])
            ->orderBy('logs.id')
            ->chunkById(200, function ($rows): void {
                foreach ($rows as $row) {
                    $startedAt = CarbonImmutable::parse($row->started_at);
                    $finishedAt = CarbonImmutable::parse($row->reconciled_at);
                    $durationMs = max(0, min(4_294_967_295, $startedAt->diffInMilliseconds($finishedAt)));

                    DB::table('api_request_logs')
                        ->where('id', $row->log_id)
                        ->whereNull('finished_at')
                        ->update([
                            'finished_at' => $finishedAt,
                            'duration_ms' => $durationMs,
                            'updated_at' => now(),
                        ]);
                }
            }, 'logs.id', 'log_id');
    }

    public function down(): void
    {
        // Timing repair is intentionally not reversed. The request had already
        // stopped when reconciliation began; this migration only persists that fact.
    }
};
