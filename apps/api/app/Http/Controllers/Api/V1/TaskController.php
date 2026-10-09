<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\ChangeTaskStatus;
use App\Actions\CreateTask;
use App\Actions\DeleteTask;
use App\Actions\SaveTaskCheckpoint;
use App\Actions\UpdateTask;
use App\Core\Exceptions\VersionConflict;
use App\Core\Workspaces\CurrentWorkspace;
use App\Enums\TaskAssignee;
use App\Enums\TaskMode;
use App\Enums\TaskStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\TaskEventResource;
use App\Http\Resources\TaskResource;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class TaskController extends Controller
{
    /**
     * Lowercase keys like "story" or "scene_outline".
     */
    private const KEY_RULE = 'regex:/^[a-z][a-z0-9_]*$/';

    public function index(Request $request, CurrentWorkspace $current): AnonymousResourceCollection
    {
        $request->validate([
            'project' => ['sometimes', 'uuid'],
            'status' => ['sometimes', Rule::enum(TaskStatus::class)],
            'module' => ['sometimes', 'string', self::KEY_RULE],
        ]);

        $tasks = Task::where('workspace_id', $current->id())
            ->whereHas('project')
            ->when($request->query('project'), fn ($query, $id) => $query->where('project_id', $id))
            ->when($request->query('status'), fn ($query, $status) => $query->where('status', $status))
            ->when($request->query('module'), fn ($query, $module) => $query->where('module', $module))
            ->with('dependsOn')
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->cursorPaginate($this->perPage($request));

        return TaskResource::collection($tasks);
    }

    public function store(Request $request, Project $project, CreateTask $createTask): TaskResource
    {
        Gate::authorize('create', [Task::class, $project]);

        $data = $request->validate([
            'module' => ['required', 'string', 'max:64', self::KEY_RULE],
            'kind' => ['required', 'string', 'max:64', self::KEY_RULE],
            'title' => ['required', 'string', 'max:255'],
            'mode' => ['sometimes', Rule::enum(TaskMode::class)],
            'assigned_to' => ['sometimes', Rule::enum(TaskAssignee::class)],
            'parent_task_id' => ['sometimes', 'nullable', 'uuid'],
        ], [
            'module.regex' => 'The module must be a lowercase key like "story" (letters, digits, underscores).',
            'kind.regex' => 'The kind must be a lowercase key like "outline" (letters, digits, underscores).',
        ]);

        return new TaskResource($createTask->handle($project, $request->user(), $data)->load('dependsOn'));
    }

    public function show(Task $task): TaskResource
    {
        Gate::authorize('view', $task);

        return new TaskResource($task->load('dependsOn'));
    }

    public function update(Request $request, Task $task, UpdateTask $updateTask): TaskResource
    {
        Gate::authorize('update', $task);

        $data = $request->validate([
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'mode' => ['sometimes', Rule::enum(TaskMode::class)],
        ]);

        return new TaskResource($updateTask->handle($task, $request->user(), $data)->load('dependsOn'));
    }

    public function destroy(Request $request, Task $task, DeleteTask $deleteTask): Response
    {
        Gate::authorize('delete', $task);

        $deleteTask->handle($task, $request->user());

        return response()->noContent();
    }

    public function status(Request $request, Task $task, ChangeTaskStatus $changeStatus): TaskResource
    {
        Gate::authorize('update', $task);

        $data = $request->validate(['to' => ['required', Rule::enum(TaskStatus::class)]]);

        $task = $changeStatus->handle($task, TaskStatus::from($data['to']), $request->user());

        return new TaskResource($task->load('dependsOn'));
    }

    /**
     * Body: { base_version, checkpoint: {...} }. A stale base_version gets 409 and the server copy.
     */
    public function checkpoint(Request $request, Task $task, SaveTaskCheckpoint $saveCheckpoint): TaskResource|JsonResponse
    {
        Gate::authorize('update', $task);

        $request->validate([
            'base_version' => ['required', 'integer'],
            'checkpoint' => ['present'],
        ]);

        $checkpoint = $this->jsonBody($request)->checkpoint;

        if (! is_object($checkpoint)) {
            throw ValidationException::withMessages(['checkpoint' => 'The checkpoint must be a JSON object.']);
        }

        try {
            $task = $saveCheckpoint->handle($task, $request->integer('base_version'), $checkpoint, $request->user());
        } catch (VersionConflict $conflict) {
            return response()->json([
                'message' => $conflict->getMessage(),
                'data' => new TaskResource($conflict->current->load('dependsOn')),
            ], 409);
        }

        return new TaskResource($task->load('dependsOn'));
    }

    public function events(Request $request, Task $task): AnonymousResourceCollection
    {
        Gate::authorize('view', $task);

        $events = $task->events()
            ->orderBy('created_at')
            ->orderBy('id')
            ->cursorPaginate($this->perPage($request));

        return TaskEventResource::collection($events);
    }
}
