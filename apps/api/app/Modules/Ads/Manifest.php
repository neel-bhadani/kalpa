<?php

namespace App\Modules\Ads;

use App\Core\Modules\ModuleManifest;

class Manifest implements ModuleManifest
{
    public function key(): string
    {
        return 'ads';
    }

    public function name(): string
    {
        return 'Ads';
    }

    public function icon(): string
    {
        return 'megaphone';
    }

    public function version(): string
    {
        return '0.1.0';
    }

    public function taskKinds(): array
    {
        return [
            'create_ad' => 'Create ad',
        ];
    }
}
