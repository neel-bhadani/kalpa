<?php

namespace Tests\Fixtures;

/**
 * A module that tries to own "image", a core kind.
 */
class CoreKindManifest extends DuplicateKindManifest
{
    public function assetKinds(): array
    {
        return ['image' => 'Image'];
    }
}
