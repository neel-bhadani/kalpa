<?php

use App\Actions\CreateProject;
use App\Actions\CreateTask;
use App\Actions\RegisterUser;
use App\Enums\WorkspaceRole;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
| Feature tests run inside a Laravel test case, on a fresh kalpa_test database.
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
| Helpers for workspace-scoped tests.
*/

// A new user who owns their personal workspace.
function owner(string $name = 'Ada'): User
{
    return app(RegisterUser::class)->handle($name, Str::lower($name).'-'.Str::random(6).'@example.com', 'secret-password');
}

function workspaceOf(User $user): Workspace
{
    return $user->workspaces()->firstOrFail();
}

// A new user added to the workspace with the given role.
function memberOf(Workspace $workspace, WorkspaceRole $role): User
{
    $user = User::factory()->create();

    WorkspaceMember::create(['workspace_id' => $workspace->id, 'user_id' => $user->id, 'role' => $role]);

    return $user;
}

// Send the next requests as $user, in $workspace.
function actingIn(User $user, Workspace $workspace): TestCase
{
    return test()->actingAs($user)->withHeader('X-Workspace-Id', $workspace->id);
}

function makeProject(User $user, ?Workspace $workspace = null, string $name = 'Film'): Project
{
    return app(CreateProject::class)->handle($workspace ?? workspaceOf($user), $user, ['name' => $name]);
}

function makeTask(Project $project, User $user, string $title = 'Write outline'): Task
{
    return app(CreateTask::class)->handle($project, $user, ['module' => 'story', 'kind' => 'outline', 'title' => $title]);
}
