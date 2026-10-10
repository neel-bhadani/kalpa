<?php

namespace App\Actions;

use App\Models\Asset;

class DeleteAsset
{
    /**
     * Soft delete. Versions, files and links stay for history; files aren't garbage-collected yet.
     */
    public function handle(Asset $asset): void
    {
        $asset->delete();
    }
}
