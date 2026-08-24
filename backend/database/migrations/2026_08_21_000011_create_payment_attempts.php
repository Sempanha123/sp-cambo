<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_attempts', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('order_id')->constrained()->restrictOnDelete();
            $table->string('status', 20)->default('PENDING')->index();
            $table->text('qr_payload');
            $table->char('qr_md5', 32)->unique();
            $table->unsignedBigInteger('amount_minor');
            $table->char('currency', 3);
            $table->unsignedTinyInteger('currency_exponent');
            $table->string('transaction_hash', 64)->nullable()->unique();
            $table->timestamp('expires_at')->index();
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
            $table->index(['order_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_attempts');
    }
};
