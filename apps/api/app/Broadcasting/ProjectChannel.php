<?php

namespace App\Broadcasting;

use App\Models\Project;
use App\Models\User;

class ProjectChannel extends WorkspaceChannel
{
    public function join(User $user, string $projectId): bool
    {
        return $this->isMember($user, Project::find($projectId)?->workspace_id);
    }
}
