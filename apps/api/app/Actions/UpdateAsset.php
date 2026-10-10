<?php

namespace App\Actions;

use App\Models\Asset;

class UpdateAsset
{
    /**
     * Change the asset's own details. The spec only changes through new versions.
     *
     * @param  array{name?: string, tags?: list<string>, licence?: ?string, attribution?: ?string}  $changes
     */
    public function handle(Asset $asset, array $changes): Asset
    {
        $asset->fill($changes)->save();

        return $asset;
    }
}
