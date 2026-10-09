<?php

namespace App\Policies;

use App\Core\Workspaces\CurrentWorkspace;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;

/**
 * Viewers read tasks; editors and owners also create, change and delete them.
 * Tasks outside the current workspace are always refused.
 */
class TaskPolicy
{
    public function __construct(private CurrentWorkspace $current) {}

    public function view(User $user, Task $task): bool
    {
        return $this->current->contains($task);
    }

    public function create(User $user, Project $project): bool
    {
        return $this->current->contains($project) && $this->current->canWrite();
    }

    public function update(User $user, Task $task): bool
    {
        return $this->current->contains($task) && $this->current->canWrite();
    }

    public function delete(User $user, Task $task): bool
    {
        return $this->current->contains($task) && $this->current->canWrite();
    }
}
