<?php

namespace App\Actions;

use App\Core\Sync\ChangeRecorder;
use App\Enums\TaskEventType;
use App\Enums\TaskStatus;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ChangeTaskStatus
{
    public function __construct(
        private RecordTaskEvent $recordEvent,
        private ChangeRecorder $changes,
    ) {}

    /**
     * Move the task to a new status if TaskStatus allows it, and record the move.
     * A null user means the system moved it (e.g. a job finished); $data goes into the event.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException when the move is not allowed
     */
    public function handle(Task $task, TaskStatus $to, ?User $user, array $data = []): Task
    {
        return DB::transaction(function () use ($task, $to, $user, $data) {
            $task = Task::whereKey($task->id)->lockForUpdate()->firstOrFail();
            $from = $task->status;

            if (! $from->canTransitionTo($to)) {
                throw ValidationException::withMessages([
                    'to' => "A task can't move from {$from->value} to {$to->value}.",
                ]);
            }

            $task->status = $to;

            if ($to === TaskStatus::InProgress) {
                $task->started_at ??= now();
                // Reopening a completed task clears its completion time.
                $task->completed_at = null;
            }

            if ($to === TaskStatus::Completed) {
                $task->completed_at = now();
            }

            $task->row_version++;
            $task->save();

            $this->recordEvent->handle($task, TaskEventType::StatusChanged, $user, $data, from: $from, to: $to);
            $this->changes->upsert($task);

            return $task;
        });
    }
}
