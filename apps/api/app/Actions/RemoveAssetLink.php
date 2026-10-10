<?php

namespace App\Actions;

use App\Models\Asset;
use App\Models\AssetLink;

class RemoveAssetLink
{
    public function handle(Asset $from, string $toAssetId, string $relation): void
    {
        AssetLink::where('from_asset_id', $from->id)
            ->where('to_asset_id', $toAssetId)
            ->where('relation', $relation)
            ->delete();
    }
}
