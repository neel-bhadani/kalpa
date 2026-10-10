<?php

namespace App\Events;

use App\Models\Job;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A job's status or progress changed. Sent to the job, its task and the user who queued it.
 */
class JobUpdated implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    // SerializesModels: the queued broadcast re-reads the row, so events that arrive
    // out of order still carry the latest state.
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Job $job) {}

    public function broadcastOn(): array
    {
        return array_values(array_filter([
            new PrivateChannel("job.{$this->job->id}"),
            $this->job->task_id ? new PrivateChannel("task.{$this->job->task_id}") : null,
            $this->job->created_by ? new PrivateChannel("user.{$this->job->created_by}") : null,
        ]));
    }

    public function broadcastAs(): string
    {
        return 'JobUpdated';
    }

    public function broadcastWith(): array
    {
        $job = $this->job;

        return [
            'id' => $job->id,
            'type' => $job->type,
            'target' => $job->target->value,
            'status' => $job->status->value,
            'progress' => $job->progress,
            'task_id' => $job->task_id,
            'worker_id' => $job->worker_id,
            'error' => $job->error,
            'updated_at' => $job->updated_at?->toJSON(),
        ];
    }
}
