<?php

namespace App\Modules\Assets3d;

use App\Core\Modules\ModuleManifest;

class Manifest implements ModuleManifest
{
    public function key(): string
    {
        return 'assets3d';
    }

    public function name(): string
    {
        return '3D Assets';
    }

    public function icon(): string
    {
        return 'box';
    }

    public function version(): string
    {
        return '0.1.0';
    }

    public function taskKinds(): array
    {
        return [
            'create_object' => 'Create object',
            'create_environment' => 'Create environment',
        ];
    }

    public function assetKinds(): array
    {
        return [
            'object3d' => '3D object',
            'environment' => 'Environment',
            'material' => 'Material',
        ];
    }

    public function jobTypes(): array
    {
        return [];
    }
}
