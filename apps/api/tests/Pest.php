<?php

use App\Actions\CreateJob;
use App\Actions\CreateProject;
use App\Actions\CreateTask;
use App\Actions\RegisterUser;
use App\Enums\JobPriority;
use App\Enums\WorkerKind;
use App\Enums\WorkerStatus;
use App\Enums\WorkspaceRole;
use App\Models\ComputeWorker;
use App\Models\Job;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
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

function makeTask(Project $project, User $user, string $title = 'Write outline', string $module = 'story', string $kind = 'write_story'): Task
{
    return app(CreateTask::class)->handle($project, $user, ['module' => $module, 'kind' => $kind, 'title' => $title]);
}

/*
| Upload helpers (real kalpa-test bucket). S3 parts must be at least 5 MB, except the last.
*/

const PART = 5 * 1024 * 1024;

function startUpload(string $bytes, string $name = 'clip.mp4', string $mime = 'video/mp4'): array
{
    return [
        'file_name' => $name,
        'size_bytes' => strlen($bytes),
        'mime' => $mime,
        'sha256' => hash('sha256', $bytes),
    ];
}

// Presign the parts, PUT each slice to storage and return [{number, etag}].
function putParts(string $uploadId, string $bytes, int $partSize = PART): array
{
    $numbers = range(1, (int) ceil(strlen($bytes) / $partSize));
    $urls = test()->postJson("/api/v1/uploads/{$uploadId}/parts", ['part_numbers' => $numbers])
        ->assertOk()
        ->json('data.urls');

    return array_map(function (array $part) use ($bytes, $partSize) {
        $response = Http::withBody(substr($bytes, ($part['number'] - 1) * $partSize, $partSize), 'application/octet-stream')
            ->put($part['url'])
            ->throw();

        return ['number' => $part['number'], 'etag' => trim($response->header('ETag'), '"')];
    }, $urls);
}

// Start, send and complete an upload of $bytes in the workspace; returns the complete response's data.
function uploadBytes(User $user, Workspace $workspace, string $bytes, string $name = 'clip.mp4', string $mime = 'video/mp4', ?string $claimedSha = null): array
{
    $start = [...startUpload($bytes, $name, $mime), ...($claimedSha ? ['sha256' => $claimedSha] : [])];
    $uploadId = actingIn($user, $workspace)->postJson('/api/v1/uploads', $start)->assertCreated()->json('data.upload.id');
    $parts = putParts($uploadId, $bytes);

    return [...actingIn($user, $workspace)->postJson("/api/v1/uploads/{$uploadId}/complete", ['parts' => $parts])->assertOk()->json('data'), 'upload_id' => $uploadId];
}

/*
| Worker helpers.
*/

// A paired local worker for the user, and its token.
function pairedWorker(User $user, Workspace $workspace, string $name = 'Laptop worker'): array
{
    $token = 'kw_'.Str::random(48);

    $worker = ComputeWorker::create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'kind' => WorkerKind::Local,
        'name' => $name,
        'capabilities' => [],
        'pairing_token_hash' => hash('sha256', $token),
        'status' => WorkerStatus::Online,
    ]);

    return [$worker, $token];
}

// Send the next requests as a paired worker.
function asWorker(string $token): TestCase
{
    return test()->withToken($token);
}

function demoJob(User $user, Workspace $workspace, ?Task $task = null, string $target = 'worker', JobPriority $priority = JobPriority::Normal): Job
{
    return app(CreateJob::class)->handle($workspace->id, $user, 'demo.count', $target, $task, ['n' => 3], $priority);
}
