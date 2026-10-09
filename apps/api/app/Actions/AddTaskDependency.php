<?php

namespace App\Actions;

use App\Enums\TaskEventType;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskDependency;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AddTaskDependency
{
    public function __construct(private RecordTaskEvent $recordEvent) {}

    /**
     * Make $task wait for $dependsOn. Both must be in the same project, and no cycle may form.
     *
     * @throws ValidationException when the dependency is not allowed
     */
    public function handle(Task $task, Task $dependsOn, User $user): TaskDependency
    {
        if ($task->is($dependsOn)) {
            $this->fail('A task cannot depend on itself.');
        }

        if ($task->project_id !== $dependsOn->project_id) {
            $this->fail('Both tasks must be in the same project.');
        }

        return DB::transaction(function () use ($task, $dependsOn, $user) {
            // One dependency change per project at a time, so two requests can't build a cycle together.
            Project::whereKey($task->project_id)->lockForUpdate()->first();

            if ($this->reaches($dependsOn->id, $task->id)) {
                $this->fail('This would create a dependency cycle.');
            }

            $dependency = TaskDependency::firstOrCreate(
                ['task_id' => $task->id, 'depends_on_task_id' => $dependsOn->id],
                ['workspace_id' => $task->workspace_id],
            );

            if ($dependency->wasRecentlyCreated) {
                $this->recordEvent->handle($task, TaskEventType::DependencyAdded, $user, ['depends_on_task_id' => $dependsOn->id]);
            }

            return $dependency;
        });
    }

    /**
     * Whether $fromId already depends on $toId, directly or through other tasks.
     */
    private function reaches(string $fromId, string $toId): bool
    {
        return DB::selectOne(<<<'SQL'
            WITH RECURSIVE reach(id) AS (
                SELECT depends_on_task_id FROM task_dependencies WHERE task_id = :from
                UNION
                SELECT d.depends_on_task_id FROM task_dependencies d JOIN reach r ON d.task_id = r.id
            )
            SELECT EXISTS (SELECT 1 FROM reach WHERE id = :to) AS found
            SQL, ['from' => $fromId, 'to' => $toId])->found;
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['depends_on_task_id' => $message]);
    }
}
