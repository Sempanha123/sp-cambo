<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fulfillment_claims', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants');
            $table->foreignUlid('order_id')->constrained('orders');
            $table->foreignId('order_item_id')->constrained('order_items');
            $table->json('claim_snapshot');
            $table->timestamp('expires_at');
            $table->string('status', 20)->default('PENDING');
            $table->foreignUlid('api_key_id')->nullable()->constrained('api_keys');
            $table->string('source_idempotency_key', 191)->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'order_item_id']);
            $table->unique(['tenant_id', 'source_idempotency_key']);
            $table->unique(['tenant_id', 'api_key_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fulfillment_claims');
    }
};
