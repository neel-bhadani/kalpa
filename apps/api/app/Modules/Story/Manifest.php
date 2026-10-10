<?php

namespace App\Modules\Story;

use App\Core\Modules\ModuleManifest;

class Manifest implements ModuleManifest
{
    public function key(): string
    {
        return 'story';
    }

    public function name(): string
    {
        return 'Story & Script';
    }

    public function icon(): string
    {
        return 'book-open';
    }

    public function version(): string
    {
        return '0.1.0';
    }

    public function taskKinds(): array
    {
        return [
            'write_story' => 'Write story',
            'write_script' => 'Write script',
        ];
    }

    public function assetKinds(): array
    {
        return [
            'story' => 'Story',
            'script' => 'Script',
        ];
    }
}
