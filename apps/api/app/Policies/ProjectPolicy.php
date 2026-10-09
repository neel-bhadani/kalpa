<?php

namespace App\Policies;

use App\Core\Workspaces\CurrentWorkspace;
use App\Models\Project;
use App\Models\User;

/**
 * Viewers read, editors also write, only owners delete a project.
 * Projects outside the current workspace are always refused.
 */
class ProjectPolicy
{
    public function __construct(private CurrentWorkspace $current) {}

    public function view(User $user, Project $project): bool
    {
        return $this->current->contains($project);
    }

    public function create(User $user): bool
    {
        return $this->current->canWrite();
    }

    public function update(User $user, Project $project): bool
    {
        return $this->current->contains($project) && $this->current->canWrite();
    }

    public function delete(User $user, Project $project): bool
    {
        return $this->current->contains($project) && $this->current->isOwner();
    }
}
