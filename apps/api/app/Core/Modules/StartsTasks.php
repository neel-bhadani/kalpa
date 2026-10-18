<?php

namespace App\Core\Modules;

use App\Models\Task;
use App\Models\User;

/**
 * A module manifest may implement this to set up a new task of its own (e.g. make the asset the
 * task works on). Called inside the transaction that creates the task.
 */
interface StartsTasks
{
    public function taskCreated(Task $task, User $user): void;
}
