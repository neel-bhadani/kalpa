<?php

namespace App\Notifications;

use App\Models\Job;
use Illuminate\Notifications\Notification;

class JobFinished extends Notification
{
    public function __construct(private Job $job) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'job_finished',
            'title' => 'Job finished',
            'body' => "{$this->job->type}".($this->job->task ? " for \"{$this->job->task->title}\"" : '').' is done.',
            'job_id' => $this->job->id,
            'task_id' => $this->job->task_id,
        ];
    }
}
