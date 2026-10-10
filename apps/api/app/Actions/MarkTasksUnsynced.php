<?php

namespace App\Actions;

use App\Core\Sync\ChangeRecorder;
use App\Models\Task;
use Illuminate\Support\Facades\DB;

class MarkTasksUnsynced
{
    public function __construct(private ChangeRecorder $changes) {}

    /**
     * A device is closing with edits it couldn't send yet: flag those tasks ("Unsaved" on other devices).
     * The flag is cleared when the device's ops for the task are applied.
     *
     * @param  list<string>  $taskIds
     */
    public function handle(string $workspaceId, array $taskIds): int
    {
        return DB::transaction(function () use ($workspaceId, $taskIds) {
            $tasks = Task::where('workspace_id', $workspaceId)
                ->whereKey($taskIds)
                ->where('has_unsynced_changes', false)
                ->get();

            foreach ($tasks as $task) {
                $task->forceFill(['has_unsynced_changes' => true])->save();
                $this->changes->upsert($task);
            }

            return $tasks->count();
        });
    }
}
