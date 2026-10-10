<?php

namespace App\Actions;

use App\Core\Jobs\JobLogger;
use App\Core\Jobs\JobTaskSync;
use App\Enums\JobLogLevel;
use App\Enums\JobStatus;
use App\Models\ComputeWorker;
use App\Models\Job;
use Illuminate\Support\Facades\DB;

class HeartbeatJob
{
    public function __construct(
        private JobTaskSync $taskSync,
        private JobLogger $logger,
    ) {}

    /**
     * The worker is still on it: extend the lease, record progress (also on the task) and an optional log line.
     * A job that was paused, cancelled or taken away is returned unchanged; its status tells the worker to stop.
     *
     * @param  array{level?: string, message: string, data?: array<string, mixed>}|null  $log
     */
    public function handle(ComputeWorker $worker, Job $job, ?int $progress, ?array $log): Job
    {
        return DB::transaction(function () use ($worker, $job, $progress, $log) {
            $job = Job::whereKey($job->id)->lockForUpdate()->firstOrFail();

            if ($job->worker_id !== $worker->id || ! in_array($job->status, [JobStatus::Assigned, JobStatus::Running], true)) {
                return $job;
            }

            $job->forceFill([
                'status' => JobStatus::Running,
                'started_at' => $job->started_at ?? now(),
                'progress' => $progress === null ? $job->progress : max(0, min(100, $progress)),
                'lease_until' => now()->addSeconds(config('jobs.lease_seconds')),
            ])->save();

            $worker->forceFill(['last_heartbeat_at' => now()])->save();

            if ($log !== null) {
                $this->logger->log($job, $log['message'], JobLogLevel::tryFrom($log['level'] ?? '') ?? JobLogLevel::Info, $log['data'] ?? []);
            }

            $this->taskSync->progress($job);

            return $job;
        });
    }
}
