<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fulfillment_claims', function (Blueprint $table): void {
            $table->dropUnique(['tenant_id', 'api_key_id']);
            $table->index(['tenant_id', 'api_key_id']);
        });
    }

    public function down(): void
    {
        Schema::table('fulfillment_claims', function (Blueprint $table): void {
            $table->dropIndex(['tenant_id', 'api_key_id']);
            $table->unique(['tenant_id', 'api_key_id']);
        });
    }
};
