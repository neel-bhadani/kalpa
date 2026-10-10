<?php

use App\Actions\ExpireStaleUploads;
use App\Actions\MarkStaleWorkersOffline;
use App\Actions\RequeueExpiredJobs;
use App\Core\Modules\ModuleRegistry;
use App\Core\Storage\ObjectStorage;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

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
