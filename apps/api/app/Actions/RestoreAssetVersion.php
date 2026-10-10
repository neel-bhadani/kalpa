<?php

namespace App\Actions;

use App\Core\Assets\AssetVersionWriter;
use App\Models\Asset;
use App\Models\AssetVersion;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class RestoreAssetVersion
{
    public function __construct(private AssetVersionWriter $versions) {}

    /**
     * Add a new version that copies version $number's spec and files. History is never rewritten.
     */
    public function handle(Asset $asset, User $user, int $number): AssetVersion
    {
        return DB::transaction(function () use ($asset, $user, $number) {
            $asset = Asset::whereKey($asset->id)->lockForUpdate()->firstOrFail();
            $source = $asset->versions()->where('number', $number)->firstOrFail();

            return $this->versions->write(
                $asset,
                $asset->currentVersion,
                $source->spec,
                null,
                $this->versions->filesOf($source),
                "Restored v{$number}",
                $user,
            );
        });
    }
}
