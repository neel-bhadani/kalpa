<?php

namespace App\Broadcasting;

use App\Models\User;
use App\Models\WorkspaceMember;

/**
 * Base for channels about workspace things (projects, tasks, jobs): members of that workspace may listen.
 */
abstract class WorkspaceChannel
{
    protected function isMember(User $user, ?string $workspaceId): bool
    {
        return $workspaceId !== null
            && WorkspaceMember::where('workspace_id', $workspaceId)->where('user_id', $user->id)->exists();
    }
}
