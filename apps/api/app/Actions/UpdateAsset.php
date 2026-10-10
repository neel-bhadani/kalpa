<?php

namespace App\Actions;

use App\Core\Sync\ChangeRecorder;
use App\Models\Asset;
use Illuminate\Support\Facades\DB;

class UpdateAsset
{
    public function __construct(private ChangeRecorder $changes) {}

    /**
     * Change the asset's own details. The spec only changes through new versions.
     *
     * @param  array{name?: string, tags?: list<string>, licence?: ?string, attribution?: ?string}  $changes
     */
    public function handle(Asset $asset, array $changes): Asset
    {
        return DB::transaction(function () use ($asset, $changes) {
            $asset->fill($changes)->save();
            $this->changes->upsert($asset);

            return $asset;
        });
    }
}
