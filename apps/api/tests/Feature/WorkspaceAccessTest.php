<?php

use App\Enums\WorkspaceRole;

it('needs the X-Workspace-Id header', function () {
    $this->actingAs(owner())->getJson('/api/v1/projects')->assertStatus(400);
});

it('refuses non-members', function () {
    $ada = owner('Ada');
    $bob = owner('Bob');
    $project = makeProject($ada);

    actingIn($bob, workspaceOf($ada))->getJson('/api/v1/projects')->assertForbidden();
    actingIn($bob, workspaceOf($ada))->getJson("/api/v1/projects/{$project->id}")->assertForbidden();
});

it("refuses a project from another workspace, even through the user's own workspace", function () {
    $ada = owner('Ada');
    $bob = owner('Bob');
    $project = makeProject($ada);
    $task = makeTask($project, $ada);

    actingIn($bob, workspaceOf($bob))->getJson("/api/v1/projects/{$project->id}")->assertForbidden();
    actingIn($bob, workspaceOf($bob))->getJson("/api/v1/tasks/{$task->id}")->assertForbidden();
    actingIn($bob, workspaceOf($bob))->getJson('/api/v1/tasks')->assertOk()->assertJsonCount(0, 'data');
});

it('lets a viewer read but not write', function () {
    $ada = owner();
    $workspace = workspaceOf($ada);
    $project = makeProject($ada);
    $task = makeTask($project, $ada);
    $viewer = memberOf($workspace, WorkspaceRole::Viewer);

    actingIn($viewer, $workspace)->getJson('/api/v1/projects')->assertOk()->assertJsonCount(1, 'data');
    actingIn($viewer, $workspace)->getJson("/api/v1/projects/{$project->id}/memory")->assertOk();
    actingIn($viewer, $workspace)->getJson("/api/v1/tasks/{$task->id}/events")->assertOk();

    actingIn($viewer, $workspace)->postJson('/api/v1/projects', ['name' => 'Nope'])->assertForbidden();
    actingIn($viewer, $workspace)->patchJson("/api/v1/projects/{$project->id}", ['name' => 'Nope'])->assertForbidden();
    actingIn($viewer, $workspace)->patchJson("/api/v1/projects/{$project->id}/memory", [
        'base_version' => 1, 'patch' => [['op' => 'add', 'path' => '/style/look', 'value' => 'noir']],
    ])->assertForbidden();
    actingIn($viewer, $workspace)->postJson("/api/v1/projects/{$project->id}/tasks", [
        'module' => 'story', 'kind' => 'write_story', 'title' => 'Nope',
    ])->assertForbidden();
    actingIn($viewer, $workspace)->postJson("/api/v1/tasks/{$task->id}/status", ['to' => 'in_progress'])->assertForbidden();
    actingIn($viewer, $workspace)->deleteJson("/api/v1/tasks/{$task->id}")->assertForbidden();
});

it('lets an editor write but not delete a project', function () {
    $ada = owner();
    $workspace = workspaceOf($ada);
    $editor = memberOf($workspace, WorkspaceRole::Editor);

    $projectId = actingIn($editor, $workspace)->postJson('/api/v1/projects', ['name' => 'Short film'])
        ->assertCreated()
        ->json('data.id');

    actingIn($editor, $workspace)->postJson("/api/v1/projects/{$projectId}/tasks", [
        'module' => 'story', 'kind' => 'write_story', 'title' => 'Outline',
    ])->assertCreated();

    actingIn($editor, $workspace)->deleteJson("/api/v1/projects/{$projectId}")->assertForbidden();
});

it('lets the owner delete a project, which removes its tasks', function () {
    $ada = owner();
    $workspace = workspaceOf($ada);
    $project = makeProject($ada);
    $task = makeTask($project, $ada);

    actingIn($ada, $workspace)->deleteJson("/api/v1/projects/{$project->id}")->assertNoContent();

    actingIn($ada, $workspace)->getJson("/api/v1/projects/{$project->id}")->assertNotFound();
    actingIn($ada, $workspace)->getJson('/api/v1/tasks')->assertJsonCount(0, 'data');
    expect($task->refresh()->trashed())->toBeTrue()
        ->and($task->events()->where('type', 'deleted')->exists())->toBeTrue();
});
