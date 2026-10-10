<?php

namespace App\Modules\Music;

use App\Core\Modules\ModuleManifest;

class Manifest implements ModuleManifest
{
    public function key(): string
    {
        return 'music';
    }

    public function name(): string
    {
        return 'Music';
    }

    public function icon(): string
    {
        return 'music';
    }

    public function version(): string
    {
        return '0.1.0';
    }

    public function taskKinds(): array
    {
        return [
            'compose_track' => 'Compose track',
            'create_song' => 'Create song',
        ];
    }

    public function assetKinds(): array
    {
        return [
            'composition' => 'Composition',
        ];
    }

    public function jobTypes(): array
    {
        return [];
    }
}
