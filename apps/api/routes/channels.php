<?php

use App\Broadcasting\JobChannel;
use App\Broadcasting\ProjectChannel;
use App\Broadcasting\TaskChannel;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

/*
| Private channels. Authorized at POST /api/broadcasting/auth with the user's session or token.
*/

// A user's own notifications and jobs.
Broadcast::channel('user.{id}', fn (User $user, string $id) => $user->id === $id);

// Workspace things: members of the workspace may listen.
Broadcast::channel('project.{projectId}', ProjectChannel::class);
Broadcast::channel('task.{taskId}', TaskChannel::class);
Broadcast::channel('job.{jobId}', JobChannel::class);
