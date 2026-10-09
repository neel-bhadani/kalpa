<?php

use App\Core\Modules\ModuleRegistry;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// The module keys, in order, for the web app's registry test (make module-keys).
Artisan::command('modules:keys', function () {
    $this->line(json_encode(array_keys(app(ModuleRegistry::class)->all()), JSON_PRETTY_PRINT));
})->purpose('Print the installed module keys as JSON');
