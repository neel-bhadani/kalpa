<?php

namespace App\Actions;

use App\Core\Jobs\JobLogger;
use App\Core\Jobs\JobTaskSync;
use App\Enums\JobStatus;
use App\Models\Job;
use App\Notifications\JobFinished;
use Illuminate\Support\Facades\DB;

class CompleteJob
{
    public function __construct(
        private JobTaskSync $taskSync,
        private JobLogger $logger,
    ) {}

    /**
     * Record a job's success and move its task on. A job that is no longer assigned or running
     * (cancelled meanwhile, or already done) is returned unchanged.
     *
     * @param  array<string, mixed>|object  $output
     */
    public function handle(Job $job, array|object $output): Job
    {
        $job = DB::transaction(function () use ($job, $output) {
            $job = Job::whereKey($job->id)->lockForUpdate()->firstOrFail();

            if (! in_array($job->status, [JobStatus::Assigned, JobStatus::Running], true)) {
                return $job;
            }

            $job->forceFill([
                'status' => JobStatus::Succeeded,
                'output' => (object) $output,
                'progress' => 100,
                'lease_until' => null,
                'finished_at' => now(),
                'cost_seconds' => $job->started_at ? (int) $job->started_at->diffInSeconds(now()) : 0,
            ])->save();

            $this->logger->log($job, 'Succeeded');
            $this->taskSync->progress($job);
            $this->taskSync->afterJob($job);

            return $job;
        });

        // File checks and thumbnails run after every upload; only other jobs ring the bell.
        if ($job->status === JobStatus::Succeeded && $job->wasChanged('status') && ! str_starts_with($job->type, 'file.')) {
            $job->creator?->notify(new JobFinished($job));
        }

        return $job;
    }
}
