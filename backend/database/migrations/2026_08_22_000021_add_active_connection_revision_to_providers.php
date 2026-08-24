<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('providers', function (Blueprint $table): void {
            $table->foreignUlid('active_connection_revision_id')
                ->nullable()
                ->after('enabled')
                ->constrained('provider_connection_revisions')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('providers', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('active_connection_revision_id');
        });
    }
};
