<?php

namespace Tests\Feature\Feature\Api\V1;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlaygroundChatHistoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_sync_is_idempotent_and_history_is_scoped_to_the_signed_in_user(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $key = 'chat-test-12345678';

        $payload = [
            'client_key' => $key,
            'title' => 'My test chat',
            'model_alias' => 'openai-codex',
            'messages' => [
                ['role' => 'user', 'content' => 'Hello'],
                ['role' => 'assistant', 'content' => 'Hi there'],
            ],
        ];

        $first = $this->actingAs($user)->putJson('/api/v1/me/playground/chats/sync', $payload)
            ->assertSuccessful()
            ->assertJsonPath('data.client_key', $key)
            ->assertJsonPath('data.message_count', 2);

        $id = (int) $first->json('data.id');
        $payload['messages'][] = ['role' => 'user', 'content' => 'Continue'];

        $this->actingAs($user)->putJson('/api/v1/me/playground/chats/sync', $payload)
            ->assertOk()
            ->assertJsonPath('data.id', $id)
            ->assertJsonPath('data.message_count', 3);

        $this->assertDatabaseCount('playground_chats', 1);

        $this->actingAs($user)->getJson('/api/v1/me/playground/chats')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $id)
            ->assertHeader('Cache-Control', 'no-store, private, max-age=0');

        $this->actingAs($other)->getJson('/api/v1/me/playground/chats')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }


    public function test_history_cap_keeps_thirty_chats_without_offset_only_sql(): void
    {
        $user = User::factory()->create();

        for ($i = 1; $i <= 31; $i++) {
            $this->actingAs($user)->putJson('/api/v1/me/playground/chats/sync', [
                'client_key' => sprintf('cap-chat-%08d', $i),
                'title' => "Chat {$i}",
                'model_alias' => 'openai-codex',
                'messages' => [
                    ['role' => 'user', 'content' => "Message {$i}"],
                ],
            ])->assertSuccessful();
        }

        $this->assertDatabaseCount('playground_chats', 30);
        $this->assertDatabaseMissing('playground_chats', ['user_id' => $user->id, 'client_key' => 'cap-chat-00000001']);
        $this->assertDatabaseHas('playground_chats', ['user_id' => $user->id, 'client_key' => 'cap-chat-00000031']);
    }

    public function test_large_generated_code_is_truncated_for_history_instead_of_rejecting_autosave(): void
    {
        $user = User::factory()->create();
        $large = str_repeat('0123456789', 13_000);

        $response = $this->actingAs($user)->putJson('/api/v1/me/playground/chats/sync', [
            'client_key' => 'chat-large-12345678',
            'model_alias' => 'openai-codex',
            'messages' => [
                ['role' => 'user', 'content' => 'Generate a large file'],
                ['role' => 'assistant', 'content' => $large],
            ],
        ])->assertSuccessful();

        $saved = (string) $response->json('data.messages.1.content');
        $this->assertLessThan(strlen($large), strlen($saved));
        $this->assertStringContainsString('[Saved history truncated for storage]', $saved);
    }
}
