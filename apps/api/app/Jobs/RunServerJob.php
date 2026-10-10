<?php

namespace App\Jobs;

use App\Actions\CompleteJob;
use App\Actions\FailJob;
use App\Core\Jobs\JobLogger;
use App\Core\Jobs\JobTypeRegistry;
use App\Enums\JobLogLevel;
use App\Enums\JobStatus;
use App\Models\Job;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Runs one server-target row of the jobs table on the Laravel queue (Horizon).
 * Retries are counted on the jobs row (attempts / max_attempts), not by Laravel.
 */
class RunServerJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(public string $jobId) {}

    public function handle(JobTypeRegistry $types, CompleteJob $complete, FailJob $fail, JobLogger $logger): void
    {
        $job = Job::find($this->jobId);

        // Paused, cancelled or already done meanwhile.
        if ($job === null || $job->status !== JobStatus::Queued) {
            return;
        }

        $job->forceFill(['status' => JobStatus::Running, 'started_at' => $job->started_at ?? now()])->save();
        $logger->log($job, 'Started on the server');

        try {
            $output = app($types->handler($job->type))->handle($job);
        } catch (Throwable $e) {
            report($e);
            $this->retryOrFail($job->fresh(), $e, $fail, $logger);

            return;
        }

        $complete->handle($job, $output);
    }

    private function retryOrFail(Job $job, Throwable $e, FailJob $fail, JobLogger $logger): void
    {
        $job->attempts++;

        if ($job->attempts >= $job->max_attempts) {
            $job->save();
            $fail->handle($job, $e->getMessage());

            return;
        }

        $job->forceFill(['status' => JobStatus::Queued])->save();
        $logger->log($job, "Attempt {$job->attempts} failed, retrying: {$e->getMessage()}", JobLogLevel::Warning);

        self::dispatch($job->id)->delay(now()->addSeconds(10 * $job->attempts));
    }
}
