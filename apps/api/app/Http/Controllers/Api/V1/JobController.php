<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\ControlJob;
use App\Actions\CreateJob;
use App\Core\Workspaces\CurrentWorkspace;
use App\Enums\JobPriority;
use App\Enums\JobStatus;
use App\Enums\JobTarget;
use App\Http\Controllers\Controller;
use App\Http\Resources\JobResource;
use App\Models\Job;
use App\Models\Task;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Jobs as people see them: list, queue, and cancel/pause/resume/retry.
 */
class JobController extends Controller
{
    /**
     * ?status=&task=. Without a task, the jobs I queued; with one, all of that task's jobs.
     */
    public function index(Request $request, CurrentWorkspace $current): AnonymousResourceCollection
    {
        $request->validate([
            'status' => ['sometimes', Rule::enum(JobStatus::class)],
            'task' => ['sometimes', 'uuid'],
        ]);

        $jobs = Job::where('workspace_id', $current->id())
            ->when(
                $request->query('task'),
                fn ($query, $taskId) => $query->where('task_id', $taskId),
                fn ($query) => $query->where('created_by', $request->user()->id),
            )
            ->when($request->query('status'), fn ($query, $status) => $query->where('status', $status))
            ->with(['worker', 'task'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->cursorPaginate($this->perPage($request));

        return JobResource::collection($jobs);
    }

    public function store(Request $request, CurrentWorkspace $current, CreateJob $createJob): JsonResponse
    {
        Gate::authorize('create', Job::class);

        $data = $request->validate([
            'type' => ['required', 'string', 'max:100'],
            'target' => ['sometimes', Rule::enum(JobTarget::class)],
            'task_id' => ['sometimes', 'nullable', 'uuid'],
            'input' => ['sometimes', 'array'],
            'priority' => ['sometimes', Rule::enum(JobPriority::class)],
            'idempotency_key' => ['sometimes', 'string', 'max:200'],
        ]);

        $task = isset($data['task_id']) ? Task::where('workspace_id', $current->id())->findOrFail($data['task_id']) : null;

        $job = $createJob->handle(
            $current->id(),
            $request->user(),
            $data['type'],
            $data['target'] ?? null,
            $task,
            $this->jsonBody($request)->input ?? [],
            JobPriority::tryFrom($data['priority'] ?? '') ?? JobPriority::Normal,
            $data['idempotency_key'] ?? null,
        );

        return (new JobResource($job->load(['worker', 'task'])))
            ->response()
            ->setStatusCode($job->wasRecentlyCreated ? 201 : 200);
    }

    public function show(Job $job): JobResource
    {
        Gate::authorize('view', $job);

        return new JobResource($job->load(['worker', 'task', 'logs' => fn ($query) => $query->orderBy('created_at')]));
    }

    public function cancel(Request $request, Job $job, ControlJob $control): JobResource
    {
        Gate::authorize('update', $job);

        return new JobResource($control->cancel($job, $request->user())->load(['worker', 'task']));
    }

    public function pause(Request $request, Job $job, ControlJob $control): JobResource
    {
        Gate::authorize('update', $job);

        return new JobResource($control->pause($job, $request->user())->load(['worker', 'task']));
    }

    public function resume(Request $request, Job $job, ControlJob $control): JobResource
    {
        Gate::authorize('update', $job);

        return new JobResource($control->resume($job, $request->user())->load(['worker', 'task']));
    }

    public function retry(Request $request, Job $job, ControlJob $control): JobResource
    {
        Gate::authorize('update', $job);

        return new JobResource($control->retry($job, $request->user())->load(['worker', 'task']));
    }
}
