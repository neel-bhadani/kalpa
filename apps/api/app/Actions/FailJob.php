<?php

namespace App\Actions;

use App\Core\Jobs\JobLogger;
use App\Core\Jobs\JobTaskSync;
use App\Enums\JobLogLevel;
use App\Enums\JobStatus;
use App\Models\Job;
use App\Notifications\JobFailed;
use Illuminate\Support\Facades\DB;

class FailJob
{
    public function __construct(
        private JobTaskSync $taskSync,
        private JobLogger $logger,
    ) {}

    /**
     * Record a job's failure, move its task to failed and tell the person who queued it.
     * A job that already finished or was cancelled is returned unchanged.
     */
    public function handle(Job $job, string $error): Job
    {
        $job = DB::transaction(function () use ($job, $error) {
            $job = Job::whereKey($job->id)->lockForUpdate()->firstOrFail();

            if (! $job->status->isActive()) {
                return $job;
            }

            $job->forceFill([
                'status' => JobStatus::Failed,
                'error' => mb_substr($error, 0, 2000),
                'lease_until' => null,
                'finished_at' => now(),
                'cost_seconds' => $job->started_at ? (int) $job->started_at->diffInSeconds(now()) : null,
            ])->save();

            $this->logger->log($job, "Failed: {$job->error}", JobLogLevel::Error);
            $this->taskSync->afterJob($job);

            return $job;
        });

        if ($job->status === JobStatus::Failed && $job->wasChanged('status')) {
            $job->creator?->notify(new JobFailed($job));
        }

        return $job;
    }
}
