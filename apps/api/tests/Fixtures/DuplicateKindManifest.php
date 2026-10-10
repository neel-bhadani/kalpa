<?php

namespace Tests\Fixtures;

use App\Core\Modules\ModuleManifest;

/**
 * A module that claims the "script" kind, which the story module already owns.
 */
class DuplicateKindManifest implements ModuleManifest
{
    public function key(): string
    {
        return 'copycat';
    }

    public function name(): string
    {
        return 'Copycat';
    }

    public function icon(): string
    {
        return 'copy';
    }

    public function version(): string
    {
        return '0.1.0';
    }

    public function taskKinds(): array
    {
        return [];
    }

    public function assetKinds(): array
    {
        return ['script' => 'Script'];
    }
}
