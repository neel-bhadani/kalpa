<?php

namespace App\Actions;

use App\Core\Modules\ModuleRegistry;
use App\Enums\TaskEventType;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class UpdateTask
{
    public function __construct(
        private RecordTaskEvent $recordEvent,
        private ModuleRegistry $modules,
    ) {}

    /**
     * Change the title or mode, and record what changed.
     *
     * @param  array{title?: string, mode?: string}  $changes
     */
    public function handle(Task $task, User $user, array $changes): Task
    {
        // Tasks of a removed or turned-off module can't be edited.
        $this->modules->ensureTaskKind($task->workspace_id, $task->module, $task->kind);

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
