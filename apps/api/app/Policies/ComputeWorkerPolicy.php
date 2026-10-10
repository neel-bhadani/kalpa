<?php

namespace App\Policies;

use App\Core\Workspaces\CurrentWorkspace;
use App\Models\ComputeWorker;
use App\Models\User;

/**
 * Workers belong to the user who paired them.
 */
class ComputeWorkerPolicy
{
    public function __construct(private CurrentWorkspace $current) {}

    public function create(User $user): bool
    {
        return $this->current->canWrite();
    }

    public function delete(User $user, ComputeWorker $worker): bool
    {
        return $this->current->contains($worker) && $worker->user_id === $user->id;
    }
}
