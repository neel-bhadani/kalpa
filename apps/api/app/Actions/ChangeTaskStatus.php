<?php

namespace App\Actions;

use App\Enums\TaskEventType;
use App\Enums\TaskStatus;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ChangeTaskStatus
{
    public function __construct(private RecordTaskEvent $recordEvent) {}

    /**
     * Move the task to a new status if TaskStatus allows it, and record the move.
     *
     * @throws ValidationException when the move is not allowed
     */
    public function handle(Task $task, TaskStatus $to, User $user): Task
    {
        return DB::transaction(function () use ($task, $to, $user) {
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

            $this->recordEvent->handle($task, TaskEventType::StatusChanged, $user, from: $from, to: $to);

            return $task;
        });
    }
}
