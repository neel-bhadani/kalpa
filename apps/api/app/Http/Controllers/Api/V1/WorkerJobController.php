<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\ClaimJob;
use App\Actions\CompleteJob;
use App\Actions\FailJob;
use App\Actions\HeartbeatJob;
use App\Enums\JobStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\JobResource;
use App\Models\ComputeWorker;
use App\Models\Job;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The claim protocol, as workers see it (see AuthenticateWorker for who may call it).
 */
class WorkerJobController extends Controller
{
    /**
     * Body: { capabilities: [...] }. Returns { data: job } or { data: null } when there's nothing to do.
     */
    public function claim(Request $request, ClaimJob $claimJob): JsonResponse
    {
        $data = $request->validate([
            'capabilities' => ['sometimes', 'array', 'list'],
            'capabilities.*' => ['string', 'max:50'],
        ]);

        $job = $claimJob->handle($this->worker($request), $data['capabilities'] ?? []);

        return response()->json(['data' => $job ? new JobResource($job) : null]);
    }

    /**
     * Body: { progress?, log?: { level?, message, data? } }. The returned status tells the worker
     * whether to carry on (running) or stop (paused, cancelled, or the job went to another worker).
     */
    public function heartbeat(Request $request, Job $job, HeartbeatJob $heartbeat): JsonResponse
    {
        $worker = $this->ensureMine($request, $job);

        $data = $request->validate([
            'progress' => ['sometimes', 'nullable', 'integer', 'between:0,100'],
            'log' => ['sometimes', 'nullable', 'array'],
            'log.message' => ['required_with:log', 'string', 'max:2000'],
            'log.level' => ['sometimes', 'string'],
            'log.data' => ['sometimes', 'array'],
        ]);

        $job = $heartbeat->handle($worker, $job, $data['progress'] ?? null, $data['log'] ?? null);

        return response()->json(['data' => [
            'status' => $job->worker_id === $worker->id ? $job->status : 'lost',
            'lease_until' => $job->lease_until,
        ]]);
    }

    public function complete(Request $request, Job $job, CompleteJob $complete): JsonResponse
    {
        $this->ensureMine($request, $job, mustHold: true);
        $request->validate(['output' => ['sometimes', 'array']]);

        $job = $complete->handle($job, $this->jsonBody($request)->output ?? []);

        return response()->json(['data' => new JobResource($job)]);
    }

    public function fail(Request $request, Job $job, FailJob $fail): JsonResponse
    {
        $this->ensureMine($request, $job, mustHold: true);
        $data = $request->validate(['error' => ['required', 'string', 'max:2000']]);

        $job = $fail->handle($job, $data['error']);

        return response()->json(['data' => new JobResource($job)]);
    }

    private function worker(Request $request): ComputeWorker
    {
        return $request->attributes->get('worker');
    }

    /**
     * Jobs of other workspaces are refused; completing or failing needs the job to be held by this worker.
     */
    private function ensureMine(Request $request, Job $job, bool $mustHold = false): ComputeWorker
    {
        $worker = $this->worker($request);

        abort_if($job->workspace_id !== $worker->workspace_id, 403, 'This job is not in your workspace.');

        if ($mustHold && ($job->worker_id !== $worker->id || ! in_array($job->status, [JobStatus::Assigned, JobStatus::Running], true))) {
            abort(409, 'This job is no longer assigned to this worker.');
        }

        return $worker;
    }
}
