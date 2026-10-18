<?php

namespace App\Actions;

use App\Core\Modules\ModuleRegistry;
use App\Core\Modules\StartsTasks;
use App\Core\Sync\ChangeRecorder;
use App\Enums\TaskEventType;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateTask
{
    public function __construct(
        private RecordTaskEvent $recordEvent,
        private ChangeRecorder $changes,
        private ModuleRegistry $modules,
    ) {}

    /**
     * Create a draft task in the project, for an enabled module and one of its kinds. The module
     * may set the task up (StartsTasks), e.g. make the asset it works on.
     *
     * @param  array{module: string, kind: string, title: string, mode?: string, assigned_to?: string, parent_task_id?: string|null}  $attributes
     */
    public function handle(Project $project, User $user, array $attributes): Task
    {
        $this->modules->ensureTaskKind($project->workspace_id, $attributes['module'], $attributes['kind']);

        $parentId = $attributes['parent_task_id'] ?? null;

        if ($parentId !== null && ! $project->tasks()->whereKey($parentId)->exists()) {
            throw ValidationException::withMessages(['parent_task_id' => 'The parent task must be in the same project.']);
        }

        return DB::transaction(function () use ($project, $user, $attributes) {
            $task = Task::create([
                ...$attributes,
                'workspace_id' => $project->workspace_id,
                'project_id' => $project->id,
                'created_by' => $user->id,
            ])->refresh();

            $this->recordEvent->handle($task, TaskEventType::Created, $user, to: $task->status);
            $this->changes->upsert($task);

            $module = $this->modules->find($task->module);
            if ($module instanceof StartsTasks) {
                $module->taskCreated($task, $user);
            }

            return $task;
        });
    }
}
