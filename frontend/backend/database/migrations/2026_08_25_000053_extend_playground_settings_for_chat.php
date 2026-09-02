<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $needsGateway = ! Schema::hasColumn('playground_settings', 'gateway_base_url');
        $needsDefault = ! Schema::hasColumn('playground_settings', 'default_model_alias');
        $needsSwitching = ! Schema::hasColumn('playground_settings', 'allow_model_switching');

        if (! $needsGateway && ! $needsDefault && ! $needsSwitching) {
            return;
        }

        Schema::table('playground_settings', function (Blueprint $table) use ($needsGateway, $needsDefault, $needsSwitching): void {
            if ($needsGateway) $table->string('gateway_base_url', 512)->nullable();
            if ($needsDefault) $table->string('default_model_alias', 100)->nullable();
            if ($needsSwitching) $table->boolean('allow_model_switching')->default(true);
        });
    }

    public function down(): void
    {
        $columns = [];
        foreach (['gateway_base_url', 'default_model_alias', 'allow_model_switching'] as $column) {
            if (Schema::hasColumn('playground_settings', $column)) $columns[] = $column;
        }
        if ($columns === []) return;

        Schema::table('playground_settings', function (Blueprint $table) use ($columns): void {
            $table->dropColumn($columns);
        });
    }
};
