<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('playground_chats')) {
            Schema::create('playground_chats', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->string('client_key', 64)->nullable();
                $table->string('title', 120);
                $table->string('model_alias', 150)->nullable();
                $table->text('system_prompt')->nullable();
                $table->json('messages');
                $table->unsignedSmallInteger('message_count')->default(0);
                $table->timestamp('last_message_at')->nullable();
                $table->timestamp('expires_at')->nullable()->index();
                $table->timestamps();
                $table->index(['user_id', 'last_message_at']);
                $table->unique(['user_id', 'client_key']);
            });
            return;
        }

        if (! Schema::hasColumn('playground_chats', 'client_key')) {
            Schema::table('playground_chats', function (Blueprint $table): void {
                $table->string('client_key', 64)->nullable()->after('user_id');
            });
        }

        // Existing rows predate client-key autosave. They remain openable by id;
        // only newly synced chats need the idempotent user/client key pair.
        try {
            Schema::table('playground_chats', function (Blueprint $table): void {
                $table->unique(['user_id', 'client_key'], 'playground_chats_user_client_unique');
            });
        } catch (Throwable) {
            // Index may already exist on installations that applied a preview of
            // this migration. The schema itself is sufficient to continue.
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('playground_chats') && Schema::hasColumn('playground_chats', 'client_key')) {
            try {
                Schema::table('playground_chats', function (Blueprint $table): void {
                    $table->dropUnique('playground_chats_user_client_unique');
                });
            } catch (Throwable) {
            }

            Schema::table('playground_chats', function (Blueprint $table): void {
                $table->dropColumn('client_key');
            });
        }
    }
};
