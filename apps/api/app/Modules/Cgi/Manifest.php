<?php

namespace App\Modules\Cgi;

use App\Core\Modules\ModuleManifest;

class Manifest implements ModuleManifest
{
    public function key(): string
    {
        return 'cgi';
    }

    public function name(): string
    {
        return 'CGI & VFX';
    }

    public function icon(): string
    {
        return 'clapperboard';
    }

    public function version(): string
    {
        return '0.1.0';
    }

    public function taskKinds(): array
    {
        return [
            'create_scene' => 'Create scene',
            'create_vfx_shot' => 'Create VFX shot',
            'render_scene' => 'Render scene',
        ];
    }

    public function assetKinds(): array
    {
        return [
            'scene' => 'Scene',
            'vfx_shot' => 'VFX shot',
        ];
    }

    public function jobTypes(): array
    {
        return [];
    }
}
