<?php

namespace App\Modules\Voice;

use App\Core\Modules\ModuleManifest;

class Manifest implements ModuleManifest
{
    public function key(): string
    {
        return 'voice';
    }

    public function name(): string
    {
        return 'Voice';
    }

    public function icon(): string
    {
        return 'mic';
    }

    public function version(): string
    {
        return '0.1.0';
    }

    public function taskKinds(): array
    {
        return [
            'create_voice' => 'Create voice',
            'record_dialogue' => 'Record dialogue',
        ];
    }
}
