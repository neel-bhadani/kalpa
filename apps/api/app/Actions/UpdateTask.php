<?php

namespace App\Actions;

use App\Enums\TaskEventType;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class UpdateTask
{
    public function __construct(private RecordTaskEvent $recordEvent) {}

    /**
     * Change the title or mode, and record what changed.
     *
     * @param  array{title?: string, mode?: string}  $changes
     */
    public function handle(Task $task, User $user, array $changes): Task
    {
        return DB::transaction(function () use ($task, $user, $changes) {
            $task = Task::whereKey($task->id)->lockForUpdate()->firstOrFail();
            $task->fill($changes);

            if (! $task->isDirty()) {
                return $task;
            }

            $changed = array_keys($task->getDirty());
            $task->row_version++;
            $task->save();

            $this->recordEvent->handle($task, TaskEventType::Updated, $user, ['changes' => $task->only($changed)]);

            return $task;
        });
    }
}
