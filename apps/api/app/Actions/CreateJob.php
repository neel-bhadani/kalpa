<?php

namespace App\Actions;

use App\Core\Jobs\JobLogger;
use App\Core\Jobs\JobTaskSync;
use App\Core\Jobs\JobTypeRegistry;
use App\Enums\JobPriority;
use App\Enums\JobStatus;
use App\Enums\JobTarget;
use App\Jobs\RunServerJob;
use App\Models\Job;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CreateJob
{
    public function __construct(
        private JobTypeRegistry $types,
        private JobTaskSync $taskSync,
        private JobLogger $logger,
    ) {}

    /**
     * Queue a job. The same idempotency key in the workspace returns the existing job instead.
     * A task gets moved to waiting_job. Server jobs go to the Laravel queue; others wait to be claimed.
     *
     * @param  array<string, mixed>|object  $input
     *
     * @throws ValidationException for an unknown type, a wrong target, or a task that can't take a job
     */
    public function handle(
        string $workspaceId,
        ?User $user,
        string $type,
        ?string $target = null,
        ?Task $task = null,
        array|object $input = [],
        JobPriority $priority = JobPriority::Normal,
        ?string $idempotencyKey = null,
    ): Job {
        $target = $this->types->ensure($type, $target);
        $key = $idempotencyKey ?? (string) Str::uuid7();

        if ($existing = Job::where('workspace_id', $workspaceId)->where('idempotency_key', $key)->first()) {
            return $existing;
        }

        return DB::transaction(function () use ($workspaceId, $user, $type, $target, $task, $input, $priority, $key) {
            // firstOrCreate falls back to the existing row if a parallel request inserted the same key.
            $job = Job::firstOrCreate(
                ['workspace_id' => $workspaceId, 'idempotency_key' => $key],
                [
                    'task_id' => $task?->id,
                    'created_by' => $user?->id,
                    'type' => $type,
                    'target' => $target,
                    'status' => JobStatus::Queued,
                    'priority' => $priority,
                    'input' => (object) $input,
                    'queued_at' => now(),
                ],
            );

            if (! $job->wasRecentlyCreated) {
                return $job;
            }

            $this->logger->log($job, "Queued for {$target}");

            if ($task !== null) {
                $this->taskSync->toWaiting($task, $user, $job);
            }

            if ($job->target === JobTarget::Server) {
                RunServerJob::dispatch($job->id)->afterCommit();
            }

            return $job;
        });
    }
}
