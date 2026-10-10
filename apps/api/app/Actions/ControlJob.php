<?php

namespace App\Actions;

use App\Core\Jobs\JobLogger;
use App\Core\Jobs\JobTaskSync;
use App\Enums\JobStatus;
use App\Enums\JobTarget;
use App\Jobs\RunServerJob;
use App\Models\Job;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * What a person can do to a job: cancel, pause, resume, retry.
 * A worker learns about cancel and pause from its next heartbeat.
 */
class ControlJob
{
    public function __construct(
        private JobTaskSync $taskSync,
        private JobLogger $logger,
    ) {}

    public function cancel(Job $job, User $user): Job
    {
        return $this->change($job, $user, 'cancel', fn (Job $job) => $job->status->isActive(), function (Job $job) {
            $job->forceFill(['status' => JobStatus::Cancelled, 'lease_until' => null, 'finished_at' => now()])->save();
            $this->taskSync->afterJob($job);
        });
    }

    public function pause(Job $job, User $user): Job
    {
        return $this->change($job, $user, 'pause', fn (Job $job) => $job->status->isActive() && $job->status !== JobStatus::Paused, function (Job $job) {
            $job->forceFill(['status' => JobStatus::Paused, 'worker_id' => null, 'lease_until' => null])->save();
        });
    }

    /**
     * Back in the queue, keeping its place (queued_at).
     */
    public function resume(Job $job, User $user): Job
    {
        return $this->change($job, $user, 'resume', fn (Job $job) => $job->status === JobStatus::Paused, function (Job $job) {
            $job->forceFill(['status' => JobStatus::Queued])->save();
            $this->dispatchIfServer($job);
        });
    }

    /**
     * Start a failed or cancelled job again from scratch.
     */
    public function retry(Job $job, User $user): Job
    {
        return $this->change($job, $user, 'retry', fn (Job $job) => in_array($job->status, [JobStatus::Failed, JobStatus::Cancelled], true), function (Job $job) use ($user) {
            $job->forceFill([
                'status' => JobStatus::Queued,
                'worker_id' => null,
                'attempts' => 0,
                'progress' => 0,
                'error' => null,
                'output' => null,
                'lease_until' => null,
                'started_at' => null,
                'finished_at' => null,
                'cost_seconds' => null,
                'queued_at' => now(),
            ])->save();

            if ($job->task) {
                $this->taskSync->toWaiting($job->task, $user, $job);
            }
            $this->dispatchIfServer($job);
        });
    }

    /**
     * @param  callable(Job): bool  $allowed
     * @param  callable(Job): void  $apply
     */
    private function change(Job $job, User $user, string $action, callable $allowed, callable $apply): Job
    {
        return DB::transaction(function () use ($job, $user, $action, $allowed, $apply) {
            $job = Job::whereKey($job->id)->lockForUpdate()->firstOrFail();

            if (! $allowed($job)) {
                throw ValidationException::withMessages(['status' => "A {$job->status->value} job can't {$action}."]);
            }

            $apply($job);
            $this->logger->log($job, ucfirst($action)."d by {$user->name}", data: ['user_id' => $user->id]);

            return $job->fresh();
        });
    }

    private function dispatchIfServer(Job $job): void
    {
        if ($job->target === JobTarget::Server) {
            RunServerJob::dispatch($job->id)->afterCommit();
        }
    }
}
