<?php

namespace App\Broadcasting;

use App\Models\Task;
use App\Models\User;

class TaskChannel extends WorkspaceChannel
{
    public function join(User $user, string $taskId): bool
    {
        return $this->isMember($user, Task::find($taskId)?->workspace_id);
    }
}
