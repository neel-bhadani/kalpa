<?php

namespace App\Core\Modules;

use App\Models\Asset;
use App\Models\AssetVersion;

/**
 * A module manifest may implement this to keep its own tables in step with its asset kinds:
 * called after every new version of an asset the module owns, inside the same transaction
 * (an exception rolls the version back).
 */
interface ProjectsAssetVersions
{
    public function versionWritten(Asset $asset, AssetVersion $version): void;
}
