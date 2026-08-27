<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('telegram_purchase_alerts')) {
            return;
        }

        Schema::create('telegram_purchase_alerts', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('order_id')->nullable()->constrained('orders')->cascadeOnDelete();
            $table->foreignUlid('telegram_account_id')->nullable()->constrained('telegram_accounts')->nullOnDelete();
            $table->string('event_key', 180)->unique();
            $table->string('event_type', 48)->index();
            $table->string('audience', 24)->index();
            $table->string('chat_id', 64);
            $table->json('payload');
            $table->string('status', 24)->default('PENDING')->index();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->char('delivery_lease_token', 64)->nullable();
            $table->timestamp('delivery_lease_expires_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at'], 'tg_purchase_alert_status_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_purchase_alerts');
    }
};
