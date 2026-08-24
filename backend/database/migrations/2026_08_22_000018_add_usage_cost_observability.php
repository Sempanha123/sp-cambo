<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('usage_records', function (Blueprint $table): void {
            $table->string('provider_family', 100)->nullable()->after('public_model')->index();
            $table->unsignedBigInteger('total_tokens')->default(0)->after('reasoning_tokens');
            $table->unsignedBigInteger('upstream_cost_minor')->nullable()->after('credit_charge_minor');
        });
    }

    public function down(): void
    {
        Schema::table('usage_records', function (Blueprint $table): void {
            $table->dropIndex(['provider_family']);
            $table->dropColumn(['provider_family', 'total_tokens', 'upstream_cost_minor']);
        });
    }
};
