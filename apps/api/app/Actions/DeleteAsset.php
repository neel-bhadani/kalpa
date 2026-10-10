<?php

namespace App\Actions;

use App\Core\Sync\ChangeRecorder;
use App\Models\Asset;
use Illuminate\Support\Facades\DB;

class DeleteAsset
{
    public function __construct(private ChangeRecorder $changes) {}

    /**
     * Soft delete. Versions, files and links stay for history; files aren't garbage-collected yet.
     */
    public function handle(Asset $asset): void
    {
        DB::transaction(function () use ($asset) {
            $asset->delete();
            $this->changes->delete($asset);
        });
    }
}
