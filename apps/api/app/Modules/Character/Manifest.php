<?php

namespace App\Modules\Character;

use App\Core\Modules\ModuleManifest;

class Manifest implements ModuleManifest
{
    public function key(): string
    {
        return 'character';
    }

    public function name(): string
    {
        return 'Character';
    }

    public function icon(): string
    {
        return 'user-round';
    }

    public function version(): string
    {
        return '0.1.0';
    }

    public function taskKinds(): array
    {
        return [
            'create_character' => 'Create character',
        ];
    }

    public function assetKinds(): array
    {
        return [
            'character' => 'Character',
            'outfit' => 'Outfit',
            'motion' => 'Motion',
        ];
    }
}
