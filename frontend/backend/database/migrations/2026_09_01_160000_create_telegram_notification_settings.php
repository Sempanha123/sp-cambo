<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_notification_settings', function (Blueprint $table): void {
            $table->unsignedTinyInteger('id')->primary();
            $table->boolean('enabled')->default(true);
            $table->json('event_routes')->nullable();
            $table->boolean('qr_countdown_enabled')->default(true);
            $table->unsignedSmallInteger('qr_countdown_interval_seconds')->default(15);
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('telegram_alert_channels', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 100);
            $table->string('chat_id', 100)->unique();
            $table->boolean('enabled')->default(true)->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_alert_channels');
        Schema::dropIfExists('telegram_notification_settings');
    }
};
