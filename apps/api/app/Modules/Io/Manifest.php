<?php

namespace App\Modules\Io;

use App\Core\Modules\ModuleManifest;

class Manifest implements ModuleManifest
{
    public function key(): string
    {
        return 'io';
    }

    public function name(): string
    {
        return 'Import/Export';
    }

    public function icon(): string
    {
        return 'arrow-left-right';
    }

    public function version(): string
    {
        return '0.1.0';
    }

    public function taskKinds(): array
    {
        return [
            'import_files' => 'Import files',
            'export_files' => 'Export files',
        ];
    }

    public function assetKinds(): array
    {
        // Works with other modules' assets; owns no kind of its own.
        return [];
    }

    public function jobTypes(): array
    {
        return [];
    }
}
