<?php

namespace App\Actions;

use App\Core\Jobs\JobLogger;
use App\Enums\JobLogLevel;
use App\Enums\JobStatus;
use App\Models\Job;
use Illuminate\Support\Facades\DB;

class RequeueExpiredJobs
{
    public function __construct(
        private FailJob $failJob,
        private JobLogger $logger,
    ) {}

    /**
     * Jobs whose worker stopped sending heartbeats go back to the queue (attempts + 1),
     * or fail once they used up max_attempts. Returns [requeued, failed].
     *
     * @return array{0: int, 1: int}
     */
    public function handle(): array
    {
        [$requeued, $failed] = [0, 0];

        $expired = Job::whereIn('status', [JobStatus::Assigned, JobStatus::Running])
            ->where('lease_until', '<', now())
            ->pluck('id');

        foreach ($expired as $id) {
            $outcome = DB::transaction(function () use ($id) {
                $job = Job::whereKey($id)
                    ->whereIn('status', [JobStatus::Assigned, JobStatus::Running])
                    ->where('lease_until', '<', now())
                    ->lock('FOR UPDATE SKIP LOCKED')
                    ->first();

                if ($job === null) {
                    return null;
                }

                $job->attempts++;

                if ($job->attempts >= $job->max_attempts) {
                    $job->save();

                    return $job;
                }

                $job->forceFill(['status' => JobStatus::Queued, 'worker_id' => null, 'lease_until' => null])->save();
                $this->logger->log($job, "Lease expired; back in the queue (attempt {$job->attempts} of {$job->max_attempts})", JobLogLevel::Warning);

                return 'requeued';
            });

            if ($outcome === 'requeued') {
                $requeued++;
            } elseif ($outcome instanceof Job) {
                $this->failJob->handle($outcome, "The worker stopped responding {$outcome->attempts} times.");
                $failed++;
            }
        }

        return [$requeued, $failed];
    }
}
