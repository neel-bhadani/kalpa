<?php

namespace App\Events;

use App\Models\Task;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A task changed (status, progress, title...). Sent to the task's and its project's channels.
 */
class TaskUpdated implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    // SerializesModels: the queued broadcast re-reads the row, so events that arrive
    // out of order still carry the latest state.
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Task $task) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel("task.{$this->task->id}"), new PrivateChannel("project.{$this->task->project_id}")];
    }

    public function broadcastAs(): string
    {
        return 'TaskUpdated';
    }

    public function broadcastWith(): array
    {
        $task = $this->task;

        return [
            'id' => $task->id,
            'project_id' => $task->project_id,
            'title' => $task->title,
            'status' => $task->status->value,
            'allowed_transitions' => array_map(fn ($status) => $status->value, $task->status->allowedTransitions()),
            'mode' => $task->mode->value,
            'progress' => $task->progress,
            'has_unsynced_changes' => $task->has_unsynced_changes,
            'row_version' => $task->row_version,
            'updated_at' => $task->updated_at?->toJSON(),
            'deleted' => $task->trashed(),
        ];
    }
}
