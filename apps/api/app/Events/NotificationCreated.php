<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Queue\SerializesModels;

/**
 * A notification was stored for a user; their header bell shows it right away.
 */
class NotificationCreated implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    // SerializesModels: the queued broadcast re-reads the row, so events that arrive
    // out of order still carry the latest state.
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public DatabaseNotification $notification) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel("user.{$this->notification->notifiable_id}")];
    }

    public function broadcastAs(): string
    {
        return 'NotificationCreated';
    }

    public function broadcastWith(): array
    {
        return [
            'id' => $this->notification->id,
            'data' => $this->notification->data,
            'read_at' => null,
            'created_at' => $this->notification->created_at?->toJSON(),
        ];
    }
}
