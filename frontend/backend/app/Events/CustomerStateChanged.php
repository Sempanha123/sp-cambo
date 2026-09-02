<?php

namespace App\Events;

use App\Models\CreditLedger;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CustomerStateChanged implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly int $userId, public readonly string $event, public readonly array $safeData) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel("users.{$this->userId}")];
    }

    public function broadcastAs(): string
    {
        return $this->event;
    }

    public function broadcastWith(): array
    {
        return [
            'event' => $this->event,
            'occurred_at' => now()->toAtomString(),
            'version' => (int) CreditLedger::query()->where('user_id', $this->userId)->max('id'),
            'data' => $this->safeData,
        ];
    }
}
