<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('playground_chats')) {
            return;
        }

        Schema::create('playground_chats', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title', 120);
            $table->string('model_alias', 150)->nullable();
            $table->text('system_prompt')->nullable();
            $table->json('messages');
            $table->unsignedSmallInteger('message_count')->default(0);
            $table->timestamp('last_message_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();

            $table->index(['user_id', 'last_message_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('playground_chats');
    }
};
