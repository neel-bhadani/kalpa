<?php

namespace App\Actions;

use App\Core\Exceptions\VersionConflict;
use App\Core\Modules\ModuleRegistry;
use App\Core\Sync\ChangeRecorder;
use App\Enums\TaskEventType;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class UpdateTask
{
    public function __construct(
        private RecordTaskEvent $recordEvent,
        private ChangeRecorder $changes,
        private ModuleRegistry $modules,
    ) {}

    /**
     * Change the title or mode, and record what changed.
     * With $baseVersion (offline sync), a task that moved on since then is a conflict.
     *
     * @param  array{title?: string, mode?: string}  $changes
     *
     * @throws VersionConflict
     */
    public function handle(Task $task, User $user, array $changes, ?int $baseVersion = null): Task
    {
        // Tasks of a removed or turned-off module can't be edited.
        $this->modules->ensureTaskKind($task->workspace_id, $task->module, $task->kind);

        return DB::transaction(function () use ($task, $user, $changes, $baseVersion) {
            $task = Task::whereKey($task->id)->lockForUpdate()->firstOrFail();

            if ($baseVersion !== null && $task->row_version !== $baseVersion) {
                throw new VersionConflict($task);
            }
            $task->fill($changes);

            if (! $task->isDirty()) {
                return $task;
            }

            $changed = array_keys($task->getDirty());
            $task->row_version++;
            $task->save();

            $this->recordEvent->handle($task, TaskEventType::Updated, $user, ['changes' => $task->only($changed)]);
            $this->changes->upsert($task);

            return $task;
        });
    }
}
