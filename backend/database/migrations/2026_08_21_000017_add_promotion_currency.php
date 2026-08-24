<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('promotions', function (Blueprint $table): void {
            $table->char('currency', 3)->default('USD')->after('type');
            $table->unsignedTinyInteger('currency_exponent')->default(2)->after('currency');
        });
    }

    public function down(): void
    {
        Schema::table('promotions', function (Blueprint $table): void {
            $table->dropColumn(['currency', 'currency_exponent']);
        });
    }
};
