<?php

namespace App\Broadcasting;

use App\Models\Job;
use App\Models\User;

class JobChannel extends WorkspaceChannel
{
    public function join(User $user, string $jobId): bool
    {
        return $this->isMember($user, Job::find($jobId)?->workspace_id);
    }
}
