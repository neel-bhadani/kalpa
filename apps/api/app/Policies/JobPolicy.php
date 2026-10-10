<?php

namespace App\Policies;

use App\Core\Workspaces\CurrentWorkspace;
use App\Models\Job;
use App\Models\User;

/**
 * Members see the workspace's jobs; editors and owners queue and control them.
 */
class JobPolicy
{
    public function __construct(private CurrentWorkspace $current) {}

    public function view(User $user, Job $job): bool
    {
        return $this->current->contains($job);
    }

    public function create(User $user): bool
    {
        return $this->current->canWrite();
    }

    public function update(User $user, Job $job): bool
    {
        return $this->current->contains($job) && $this->current->canWrite();
    }
}
