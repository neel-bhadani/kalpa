<?php

namespace App\Actions;

use App\Core\Jobs\JobLogger;
use App\Core\Jobs\JobTypeRegistry;
use App\Enums\JobStatus;
use App\Enums\WorkerStatus;
use App\Models\ComputeWorker;
use App\Models\Job;
use Illuminate\Support\Facades\DB;

class ClaimJob
{
    public function __construct(
        private JobTypeRegistry $types,
        private JobLogger $logger,
    ) {}

    /**
     * Take the best queued job this worker can run, or null: highest priority first, then oldest.
     * Only the worker's own user's jobs, in its workspace. Rows other workers are claiming are skipped
     * (FOR UPDATE SKIP LOCKED), so two claims never get the same job.
     *
     * @param  list<string>  $capabilities
     */
    public function handle(ComputeWorker $worker, array $capabilities): ?Job
    {
        $worker->forceFill([
            'capabilities' => $capabilities,
            'status' => WorkerStatus::Online,
            'last_heartbeat_at' => now(),
        ])->save();

        $types = $this->types->claimable($worker->jobTarget(), $capabilities);
        if ($types === []) {
            return null;
        }

        return DB::transaction(function () use ($worker, $types) {
            $job = Job::where('workspace_id', $worker->workspace_id)
                ->where('created_by', $worker->user_id)
                ->where('status', JobStatus::Queued)
                ->where('target', $worker->jobTarget())
                ->whereIn('type', $types)
                ->orderByRaw("CASE priority WHEN 'interactive' THEN 0 WHEN 'normal' THEN 1 ELSE 2 END")
                ->orderBy('queued_at')
                ->orderBy('id')
                ->lock('FOR UPDATE SKIP LOCKED')
                ->first();

            if ($job === null) {
                return null;
            }

            $job->forceFill([
                'status' => JobStatus::Assigned,
                'worker_id' => $worker->id,
                'lease_until' => now()->addSeconds(config('jobs.lease_seconds')),
            ])->save();

            $this->logger->log($job, "Claimed by {$worker->name}", data: ['worker_id' => $worker->id]);

            return $job;
        });
    }
}
