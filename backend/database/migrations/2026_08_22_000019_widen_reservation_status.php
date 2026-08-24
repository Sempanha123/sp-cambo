<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservations', function (Blueprint $table): void {
            $table->string('status', 30)->default('ACTIVE')->change();
        });
    }

    public function down(): void
    {
        // This correction is intentionally irreversible. Narrowing the column would
        // invalidate the supported RECONCILIATION_REQUIRED lifecycle state.
    }
};
