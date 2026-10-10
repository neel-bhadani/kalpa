<?php

use App\Actions\CreateProject;
use App\Actions\CreateTask;
use App\Actions\ExpireStaleUploads;
use App\Actions\MarkStaleWorkersOffline;
use App\Actions\RegisterUser;
use App\Actions\RequeueExpiredJobs;
use App\Core\Modules\ModuleRegistry;
use App\Core\Storage\ObjectStorage;
use App\Enums\WorkspaceRole;
use App\Models\WorkspaceMember;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Str;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Module keys (in order) and asset kind owners, for the web app's registry test (make module-keys).
Artisan::command('modules:keys', function () {
    $registry = app(ModuleRegistry::class);

    $this->line(json_encode([
        'modules' => array_keys($registry->all()),
        'asset_kinds' => array_map(fn (array $kind) => $kind['owner'], $registry->assetKinds()),
    ], JSON_PRETTY_PRINT));
})->purpose('Print the module keys and asset kind owners as JSON');

// Create the storage buckets (app and tests) if missing. Runs when the api container starts.
Artisan::command('kalpa:storage-setup', function () {
    $storage = app(ObjectStorage::class);

    foreach (['kalpa', 'kalpa-test'] as $bucket) {
        $this->line($storage->ensureBucket($bucket) ? "Created bucket {$bucket}" : "Bucket {$bucket} exists");
    }
})->purpose('Create the object storage buckets');

// Abort uploads nobody finished.
Artisan::command('uploads:expire', function () {
    $this->line('Expired '.app(ExpireStaleUploads::class)->handle().' upload(s).');
})->purpose('Abort stale multipart uploads');

Schedule::command('uploads:expire')->hourly();

// Jobs whose worker went quiet go back to the queue (or fail after max_attempts).
Artisan::command('jobs:requeue-expired', function () {
    [$requeued, $failed] = app(RequeueExpiredJobs::class)->handle();
    $this->line("Requeued {$requeued} job(s), failed {$failed}.");
})->purpose('Requeue jobs whose lease expired');

Artisan::command('workers:mark-offline', function () {
    $this->line('Marked '.app(MarkStaleWorkersOffline::class)->handle().' worker(s) offline.');
})->purpose('Mark workers without recent heartbeats offline');

Schedule::command('jobs:requeue-expired')->everyMinute();
Schedule::command('workers:mark-offline')->everyMinute();

// Users for the browser end-to-end tests (make e2e), with a fresh random password each run:
// an owner with a project and task, a viewer in the owner's workspace, and a stranger.
// Local only; printed as JSON for e2e/.fixtures.json.
Artisan::command('e2e:fixtures', function () {
    abort_unless(app()->environment('local'), 403, 'e2e:fixtures only runs locally.');

    $run = Str::lower(Str::random(6));
    $password = Str::random(20);
    $register = app(RegisterUser::class);

    $owner = $register->handle('E2E Owner', "e2e-owner-{$run}@example.test", $password);
    $viewer = $register->handle('E2E Viewer', "e2e-viewer-{$run}@example.test", $password);
    $stranger = $register->handle('E2E Stranger', "e2e-stranger-{$run}@example.test", $password);
    $workspace = $owner->workspaces()->first();

    WorkspaceMember::create(['workspace_id' => $workspace->id, 'user_id' => $viewer->id, 'role' => WorkspaceRole::Viewer]);

    $project = app(CreateProject::class)->handle($workspace, $owner, ['name' => "E2E project {$run}"]);
    $task = app(CreateTask::class)->handle($project, $owner, ['module' => 'story', 'kind' => 'write_script', 'title' => "E2E fixture task {$run}"]);

    $this->line(json_encode([
        'password' => $password,
        'owner' => $owner->email,
        'viewer' => $viewer->email,
        'stranger' => $stranger->email,
        'task' => ['id' => $task->id, 'module' => $task->module, 'title' => $task->title],
    ], JSON_PRETTY_PRINT));
})->purpose('Create users for the e2e tests (local only)');
