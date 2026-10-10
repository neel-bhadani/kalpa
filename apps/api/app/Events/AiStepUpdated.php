<?php

namespace App\Events;

use App\Http\Resources\AiStepResource;
use App\Models\AiStep;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A step was added or changed status: on the task's channel (or the project's, for the Producer).
 */
class AiStepUpdated implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public AiStep $step) {}

    public function broadcastOn(): array
    {
        $session = $this->step->session;

        return [new PrivateChannel($session->task_id ? "task.{$session->task_id}" : "project.{$session->project_id}")];
    }

    public function broadcastAs(): string
    {
        return 'AiStepUpdated';
    }

    public function broadcastWith(): array
    {
        return (new AiStepResource($this->step))->resolve();
    }
}
