<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\AddAiMessage;
use App\Actions\AddAiStep;
use App\Actions\ChangeAiStepStatus;
use App\Actions\CreateProducerPlan;
use App\Actions\StartAiSession;
use App\Actions\UpdateAiSession;
use App\Enums\AiRole;
use App\Enums\AiSessionStatus;
use App\Enums\AiStepStatus;
use App\Enums\TaskMode;
use App\Http\Controllers\Controller;
use App\Http\Resources\AiSessionResource;
use App\Http\Resources\AiStepResource;
use App\Http\Resources\TaskResource;
use App\Models\AiSession;
use App\Models\AiStep;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Assistant sessions, messages and steps. The browser runs the assistant (packages/ai-runtime)
 * and records here what it says and does; the server checks every step move.
 */
class AiController extends Controller
{
    public function sessions(Task $task): JsonResponse
    {
        Gate::authorize('view', $task);

        $sessions = AiSession::where('task_id', $task->id)->orderByDesc('created_at')->get();

        return response()->json(['data' => AiSessionResource::collection($sessions)]);
    }

    public function start(Request $request, Task $task, StartAiSession $start): JsonResponse
    {
        Gate::authorize('update', $task);

        $data = $request->validate([
            'mode' => ['sometimes', Rule::enum(TaskMode::class)],
            'brain_model_key' => ['sometimes', 'string', 'max:100'],
        ]);

        $session = $start->handle($task, $request->user(), TaskMode::tryFrom($data['mode'] ?? ''), $data['brain_model_key'] ?? 'fake-brain');

        return (new AiSessionResource($session))->response()->setStatusCode(201);
    }

    public function show(AiSession $session): AiSessionResource
    {
        Gate::authorize('view', $session);

        return new AiSessionResource($session->load([
            'messages' => fn ($query) => $query->orderBy('created_at')->orderBy('id'),
            'steps' => fn ($query) => $query->orderBy('seq'),
        ]));
    }

    public function update(Request $request, AiSession $session, UpdateAiSession $update): AiSessionResource
    {
        Gate::authorize('update', $session);

        $data = $request->validate([
            'mode' => ['sometimes', Rule::enum(TaskMode::class)],
            'status' => ['sometimes', Rule::enum(AiSessionStatus::class)],
            'summary' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);

        return new AiSessionResource($update->handle($session, $data));
    }

    /**
     * Body: { role: user|assistant|tool|system, content: {...}, tokens? }.
     */
    public function addMessage(Request $request, AiSession $session, AddAiMessage $addMessage): JsonResponse
    {
        Gate::authorize('update', $session);

        $request->validate([
            'role' => ['required', Rule::enum(AiRole::class)],
            'content' => ['required', 'array'],
            'tokens' => ['sometimes', 'nullable', 'integer', 'min:0'],
        ]);

        $message = $addMessage->handle($session, AiRole::from($request->input('role')), $this->jsonBody($request)->content, $request->input('tokens'));

        return response()->json(['data' => $message->only(['id', 'session_id', 'role', 'content', 'tokens', 'created_at'])], 201);
    }

    /**
     * Body: { title, tool, args: {...}, status: planned|awaiting_review|running }.
     */
    public function addStep(Request $request, AiSession $session, AddAiStep $addStep): JsonResponse
    {
        Gate::authorize('update', $session);

        $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'tool' => ['required', 'string', 'max:100'],
            'args' => ['present', 'array'],
            'status' => ['required', Rule::enum(AiStepStatus::class)],
        ]);

        $step = $addStep->handle(
            $session,
            $request->input('title'),
            $request->input('tool'),
            (object) $this->jsonBody($request)->args,
            AiStepStatus::from($request->input('status')),
        );

        return (new AiStepResource($step))->response()->setStatusCode(201);
    }

    /**
     * Body: { status, before?, after?, error? }. A move the step can't make gets 422.
     */
    public function updateStep(Request $request, AiStep $step, ChangeAiStepStatus $change): AiStepResource
    {
        Gate::authorize('update', $step->session);

        $request->validate([
            'status' => ['required', Rule::enum(AiStepStatus::class)],
            'before' => ['sometimes', 'nullable', 'array'],
            'after' => ['sometimes', 'nullable', 'array'],
            'error' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);

        // Objects, so {} in checkpoints stays {}.
        $body = $this->jsonBody($request);
        $data = array_intersect_key((array) $body, array_flip(['before', 'after', 'error']));

        return new AiStepResource($change->handle($step, AiStepStatus::from($request->input('status')), $data));
    }

    public function approve(AiStep $step, ChangeAiStepStatus $change): AiStepResource
    {
        return $this->move($step, AiStepStatus::Running, $change);
    }

    public function reject(AiStep $step, ChangeAiStepStatus $change): AiStepResource
    {
        return $this->move($step, AiStepStatus::Rejected, $change);
    }

    public function undo(AiStep $step, ChangeAiStepStatus $change): AiStepResource
    {
        return $this->move($step, AiStepStatus::Undone, $change);
    }

    public function retry(AiStep $step, ChangeAiStepStatus $change): AiStepResource
    {
        return $this->move($step, AiStepStatus::Running, $change);
    }

    /**
     * The Producer (fake brain for now) plans a goal into tasks. Body: { goal }.
     */
    public function plan(Request $request, Project $project, CreateProducerPlan $createPlan): JsonResponse
    {
        Gate::authorize('update', $project);

        $data = $request->validate(['goal' => ['required', 'string', 'max:200']]);
        $plan = $createPlan->handle($project, $request->user(), $data['goal']);

        return response()->json(['data' => [
            'session' => new AiSessionResource($plan['session']),
            'tasks' => TaskResource::collection(collect($plan['tasks'])->each->load('dependsOn')),
            'handoffs' => collect($plan['handoffs'])->map(fn ($handoff) => $handoff->only(['id', 'task_id', 'request', 'status'])),
        ]], 201);
    }

    private function move(AiStep $step, AiStepStatus $to, ChangeAiStepStatus $change): AiStepResource
    {
        Gate::authorize('update', $step->session);

        return new AiStepResource($change->handle($step, $to));
    }
}
