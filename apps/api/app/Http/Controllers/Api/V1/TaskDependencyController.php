<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\AddTaskDependency;
use App\Actions\RemoveTaskDependency;
use App\Core\Workspaces\CurrentWorkspace;
use App\Http\Controllers\Controller;
use App\Http\Resources\TaskResource;
use App\Models\Task;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Body for both: { depends_on_task_id }. Returns the task with its depends_on list.
 */
class TaskDependencyController extends Controller
{
    public function store(Request $request, Task $task, CurrentWorkspace $current, AddTaskDependency $addDependency): TaskResource
    {
        Gate::authorize('update', $task);

        $data = $request->validate(['depends_on_task_id' => ['required', 'uuid']]);

        $dependsOn = Task::where('workspace_id', $current->id())->findOrFail($data['depends_on_task_id']);

        $addDependency->handle($task, $dependsOn, $request->user());

        return new TaskResource($task->load('dependsOn'));
    }

    public function destroy(Request $request, Task $task, RemoveTaskDependency $removeDependency): TaskResource
    {
        Gate::authorize('update', $task);

        $data = $request->validate(['depends_on_task_id' => ['required', 'uuid']]);

        $removeDependency->handle($task, $data['depends_on_task_id'], $request->user());

        return new TaskResource($task->load('dependsOn'));
    }
}
