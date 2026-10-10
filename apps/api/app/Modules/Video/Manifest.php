<?php

namespace App\Modules\Video;

use App\Core\Modules\ModuleManifest;

class Manifest implements ModuleManifest
{
    public function key(): string
    {
        return 'video';
    }

    public function name(): string
    {
        return 'Video';
    }

    public function icon(): string
    {
        return 'film';
    }

    public function version(): string
    {
        return '0.1.0';
    }

    public function taskKinds(): array
    {
        return [
            'create_video' => 'Create video',
            'edit_video' => 'Edit video',
            'upscale_video' => 'Upscale video',
        ];
    }

    public function assetKinds(): array
    {
        return [
            'timeline' => 'Timeline',
        ];
    }

    public function jobTypes(): array
    {
        return [];
    }
}
