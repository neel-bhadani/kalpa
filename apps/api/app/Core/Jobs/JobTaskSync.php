<?php

namespace App\Core\Jobs;

use App\Actions\ChangeTaskStatus;
use App\Enums\JobStatus;
use App\Enums\TaskMode;
use App\Enums\TaskStatus;
use App\Models\Job;
use App\Models\Task;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Keeps a task in step with its jobs: waiting while a job runs, then needs review,
 * back in progress (auto mode or a cancelled job), or failed. Every move is a task event.
 */
class JobTaskSync
{
    public function __construct(private ChangeTaskStatus $changeStatus) {}

    /**
     * Move the task to waiting_job, through in_progress when it can't go there directly.
     *
     * @throws ValidationException when the task's status can't take a job (e.g. archived)
     */
    public function toWaiting(Task $task, ?User $user, Job $job): void
    {
        $task = $task->fresh();
        $data = ['job_id' => $job->id];

        if ($task->status !== TaskStatus::WaitingJob) {
            if (! $task->status->canTransitionTo(TaskStatus::WaitingJob)) {
                if (! $task->status->canTransitionTo(TaskStatus::InProgress)) {
                    throw ValidationException::withMessages(['task_id' => "A task that is {$task->status->value} can't run a job."]);
                }
                $task = $this->changeStatus->handle($task, TaskStatus::InProgress, $user, $data);
            }

            $task = $this->changeStatus->handle($task, TaskStatus::WaitingJob, $user, $data);
        }

        // A new job starts from 0%, not where the last one ended.
        if ($task->progress !== 0) {
            $task->forceFill(['progress' => 0])->save();
        }
    }

    /**
     * After a job finished, move its task on, unless the task was moved by hand or other jobs still run.
     */
    public function afterJob(Job $job): void
    {
        $task = $job->task_id ? Task::find($job->task_id) : null;

        if ($task === null || $task->status !== TaskStatus::WaitingJob) {
            return;
        }

        $othersActive = Job::where('task_id', $task->id)
            ->whereKeyNot($job->id)
            ->whereIn('status', JobStatus::active())
            ->exists();

        if ($othersActive) {
            return;
        }

        $data = ['job_id' => $job->id];

        match ($job->status) {
            JobStatus::Succeeded => $this->changeStatus->handle(
                $task,
                $task->mode === TaskMode::Auto ? TaskStatus::InProgress : TaskStatus::NeedsReview,
                null,
                $data,
            ),
            JobStatus::Failed => $this->changeStatus->handle($task, TaskStatus::Failed, null, [...$data, 'error' => $job->error]),
            JobStatus::Cancelled => $this->changeStatus->handle($task, TaskStatus::InProgress, null, $data),
            default => null,
        };
    }

    /**
     * Mirror the job's progress on its task (shown as "Processing n%"). Not a task event: it changes every second.
     */
    public function progress(Job $job): void
    {
        $task = $job->task_id ? Task::find($job->task_id) : null;

        if ($task !== null && $task->progress !== $job->progress) {
            $task->progress = $job->progress;
            $task->save();
        }
    }
}
