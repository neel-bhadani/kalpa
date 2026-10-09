<?php

namespace App\Actions;

use App\Core\Exceptions\VersionConflict;
use App\Enums\TaskEventType;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class SaveTaskCheckpoint
{
    public function __construct(private RecordTaskEvent $recordEvent) {}

    /**
     * Replace the task's checkpoint, if nobody saved since $baseVersion.
     *
     * @throws VersionConflict when the task changed since $baseVersion
     */
    public function handle(Task $task, int $baseVersion, object $checkpoint, User $user): Task
    {
        return DB::transaction(function () use ($task, $baseVersion, $checkpoint, $user) {
            $task = Task::whereKey($task->id)->lockForUpdate()->firstOrFail();

            if ($task->row_version !== $baseVersion) {
                throw new VersionConflict($task);
            }

            $task->checkpoint = $checkpoint;
            $task->has_unsynced_changes = false;
            $task->row_version++;
            $task->save();

            $this->recordEvent->handle($task, TaskEventType::CheckpointSaved, $user, ['row_version' => $task->row_version]);

            return $task;
        });
    }
}
