<?php

use App\Broadcasting\JobChannel;
use App\Broadcasting\ProjectChannel;
use App\Broadcasting\TaskChannel;
use App\Enums\WorkspaceRole;
use Illuminate\Support\Facades\Broadcast;

it('lets members listen to project, task and job channels, and no one else', function () {
    $ada = owner();
    $workspace = workspaceOf($ada);
    $project = makeProject($ada);
    $task = makeTask($project, $ada);
    $job = demoJob($ada, $workspace, $task);
    $viewer = memberOf($workspace, WorkspaceRole::Viewer);
    $bob = owner('Bob');

    foreach ([[new ProjectChannel, $project->id], [new TaskChannel, $task->id], [new JobChannel, $job->id]] as [$channel, $id]) {
        expect($channel->join($ada, $id))->toBeTrue()
            ->and($channel->join($viewer, $id))->toBeTrue()
            ->and($channel->join($bob, $id))->toBeFalse()
            ->and($channel->join($ada, '01a00000-0000-7000-8000-000000000000'))->toBeFalse();
    }
});

it('lets users listen only to their own user channel', function () {
    $ada = owner();
    $bob = owner('Bob');
    $channels = Broadcast::driver()->getChannels();

    expect($channels->keys()->all())->toContain('user.{id}', 'project.{projectId}', 'task.{taskId}', 'job.{jobId}')
        ->and($channels['user.{id}']($ada, $ada->id))->toBeTrue()
        ->and($channels['user.{id}']($bob, $ada->id))->toBeFalse()
        ->and($channels['task.{taskId}'])->toBe(TaskChannel::class);
});

it('serves channel auth under /api for signed-in users only', function () {
    $this->postJson('/api/broadcasting/auth', ['socket_id' => '1.1', 'channel_name' => 'private-user.x'])->assertUnauthorized();
});
