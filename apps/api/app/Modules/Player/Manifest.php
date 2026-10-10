<?php

namespace App\Modules\Player;

use App\Core\Modules\ModuleManifest;

class Manifest implements ModuleManifest
{
    public function key(): string
    {
        return 'player';
    }

    public function name(): string
    {
        return 'Player';
    }

    public function icon(): string
    {
        return 'circle-play';
    }

    public function version(): string
    {
        return '0.1.0';
    }

    public function taskKinds(): array
    {
        return [
            'review_video' => 'Review video',
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
