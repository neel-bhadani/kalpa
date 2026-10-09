<?php

use App\Actions\ExpireStaleUploads;
use App\Core\Modules\ModuleRegistry;
use App\Core\Storage\ObjectStorage;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// The module keys, in order, for the web app's registry test (make module-keys).
Artisan::command('modules:keys', function () {
    $this->line(json_encode(array_keys(app(ModuleRegistry::class)->all()), JSON_PRETTY_PRINT));
})->purpose('Print the installed module keys as JSON');

// Create the storage buckets (app and tests) if missing. Runs when the api container starts.
Artisan::command('kalpa:storage-setup', function () {
    $storage = app(ObjectStorage::class);

    foreach (['kalpa', 'kalpa-test'] as $bucket) {
        $this->line($storage->ensureBucket($bucket) ? "Created bucket {$bucket}" : "Bucket {$bucket} exists");
    }
})->purpose('Create the object storage buckets');

// Abort uploads nobody finished. Needs a scheduler runner (added with Horizon in P0 step 7).
Artisan::command('uploads:expire', function () {
    $this->line('Expired '.app(ExpireStaleUploads::class)->handle().' upload(s).');
})->purpose('Abort stale multipart uploads');

Schedule::command('uploads:expire')->hourly();
