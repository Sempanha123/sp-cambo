<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provider_connection_revisions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignId('provider_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('route_version');
            $table->string('origin', 512);
            $table->string('connection_type', 50);
            $table->text('credential');
            $table->string('credential_suffix', 8)->nullable();
            $table->unsignedInteger('timeout_ms');
            $table->unsignedInteger('policy_version')->default(1);
            $table->string('lifecycle_status', 20)->default('PENDING')->index();
            $table->string('last_probe_status', 20)->nullable();
            $table->timestamp('last_probe_at')->nullable();
            $table->timestamp('resolve_until')->nullable()->index();
            $table->timestamps();

            $table->unique(['provider_id', 'route_version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_connection_revisions');
    }
};
