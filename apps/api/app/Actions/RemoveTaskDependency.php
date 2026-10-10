<?php

namespace App\Actions;

use App\Core\Sync\ChangeRecorder;
use App\Enums\TaskEventType;
use App\Models\Task;
use App\Models\TaskDependency;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class RemoveTaskDependency
{
    public function __construct(
        private RecordTaskEvent $recordEvent,
        private ChangeRecorder $changes,
    ) {}

    public function handle(Task $task, string $dependsOnTaskId, User $user): void
    {
        DB::transaction(function () use ($task, $dependsOnTaskId, $user) {
            $deleted = TaskDependency::where('task_id', $task->id)
                ->where('depends_on_task_id', $dependsOnTaskId)
                ->delete();

            if ($deleted > 0) {
                $this->recordEvent->handle($task, TaskEventType::DependencyRemoved, $user, ['depends_on_task_id' => $dependsOnTaskId]);
                $this->changes->upsert($task);
            }
        });
    }
}
