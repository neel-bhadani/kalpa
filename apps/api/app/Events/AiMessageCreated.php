<?php

namespace App\Events;

use App\Http\Resources\AiMessageResource;
use App\Models\AiMessage;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A chat message in an assistant session: on the task's channel (or the project's, for the Producer).
 */
class AiMessageCreated implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public AiMessage $message) {}

    public function broadcastOn(): array
    {
        $session = $this->message->session;

        return [new PrivateChannel($session->task_id ? "task.{$session->task_id}" : "project.{$session->project_id}")];
    }

    public function broadcastAs(): string
    {
        return 'AiMessageCreated';
    }

    public function broadcastWith(): array
    {
        return (new AiMessageResource($this->message))->resolve();
    }
}
