<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('telegram_purchases', function (Blueprint $table): void {
            $table->unsignedBigInteger('telegram_qr_message_id')->nullable();
            $table->timestamp('telegram_qr_expires_at')->nullable();
            $table->timestamp('telegram_qr_deleted_at')->nullable();
            $table->index(
                ['telegram_qr_deleted_at', 'telegram_qr_expires_at'],
                'telegram_purchases_qr_cleanup_idx'
            );
        });

        Schema::table('store_wallet_topups', function (Blueprint $table): void {
            $table->unsignedBigInteger('telegram_qr_message_id')->nullable();
            $table->timestamp('telegram_qr_expires_at')->nullable();
            $table->timestamp('telegram_qr_deleted_at')->nullable();
            $table->index(
                ['telegram_qr_deleted_at', 'telegram_qr_expires_at'],
                'store_wallet_topups_qr_cleanup_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::table('telegram_purchases', function (Blueprint $table): void {
            $table->dropIndex('telegram_purchases_qr_cleanup_idx');
            $table->dropColumn([
                'telegram_qr_message_id',
                'telegram_qr_expires_at',
                'telegram_qr_deleted_at',
            ]);
        });

        Schema::table('store_wallet_topups', function (Blueprint $table): void {
            $table->dropIndex('store_wallet_topups_qr_cleanup_idx');
            $table->dropColumn([
                'telegram_qr_message_id',
                'telegram_qr_expires_at',
                'telegram_qr_deleted_at',
            ]);
        });
    }
};
