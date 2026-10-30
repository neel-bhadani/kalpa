<?php

use App\Actions\CreateProject;
use App\Actions\CreateTask;
use App\Actions\ExpireStaleUploads;
use App\Actions\MarkStaleWorkersOffline;
use App\Actions\RegisterUser;
use App\Actions\RequeueExpiredJobs;
use App\Core\Assets\AssetSchemas;
use App\Core\Modules\ModuleRegistry;
use App\Core\Storage\ObjectStorage;
use App\Enums\WorkspaceRole;
use App\Models\WorkspaceMember;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

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

// make eval-story: the report on a Story assistant eval run (e2e/evals/results/<model>.json, mounted
// at /evals). Adds the schema check (each story and script against packages/schemas) to the checks
// the run made, and prints pass rate, time per brief and the RAM the model server used.
Artisan::command('eval:story-report {file} {--ram=}', function (AssetSchemas $schemas) {
    $run = json_decode((string) file_get_contents($this->argument('file')), false, 512, JSON_THROW_ON_ERROR);
    $rows = [];
    $passed = 0;

    foreach ($run->results as $result) {
        $valid = 'ok';
        try {
            $schemas->validate('story', $result->story);
            $schemas->validate('script', $result->script);
            $ids = [...array_column($result->script->scenes, 'id'), ...array_merge([], ...array_map(fn ($scene) => array_column($scene->lines, 'id'), $result->script->scenes))];
            if (count($ids) !== count(array_unique($ids))) {
                $valid = 'duplicate ids';
            }
        } catch (ValidationException $e) {
            $valid = Str::limit(implode(' ', array_map(fn ($key, $messages) => "{$key}: {$messages[0]}", array_keys($e->errors()), $e->errors())), 80);
        }

        $checks = [['label' => 'Valid spec', 'ok' => $valid === 'ok', 'detail' => $valid], ...array_map(fn ($check) => (array) $check, $result->checks)];
        $ok = collect($checks)->every(fn ($check) => $check['ok']);
        $passed += $ok ? 1 : 0;

        $rows[] = [
            $result->id,
            $result->language,
            $result->minutes,
            sprintf('%.0f s', $result->seconds),
            "{$result->reasons_from_model}/{$result->steps}",
            $result->tokens_per_second ? sprintf('%.1f', $result->tokens_per_second) : '-',
            sprintf('%.1f', $result->runtime_minutes),
            count($result->script->scenes),
            collect($checks)->filter(fn ($check) => $check['ok'])->count().'/'.count($checks),
            $ok ? 'PASS' : collect($checks)->reject(fn ($check) => $check['ok'])->map(fn ($check) => "{$check['label']} ({$check['detail']})")->implode('; '),
        ];
        foreach ($result->failed_steps as $failed) {
            $rows[] = ['', '', '', '', '', '', '', '', '', "failed step: {$failed}"];
        }
    }

    $count = count($run->results);
    $this->info("Story assistant eval — {$run->model}");
    $this->table(['Brief', 'Lang', 'Min', 'Time', 'Reasons', 'tok/s', 'Runtime', 'Scenes', 'Checks', 'Result'], $rows);
    $times = array_column($run->results, 'seconds');
    $this->line(sprintf('Pass rate: %d/%d (%d%%). Time per brief: %.0f s average, %.0f s longest.%s',
        $passed, $count, $count ? round(100 * $passed / $count) : 0, $count ? array_sum($times) / $count : 0, $count ? max($times) : 0,
        $this->option('ram') ? " Model server RAM: {$this->option('ram')} at most." : ''));
})->purpose('Report on a Story assistant eval run');
