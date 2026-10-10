<?php

namespace App\Http\Resources;

use App\Models\Asset;
use App\Models\AssetFile;
use App\Models\ProjectAsset;
use Illuminate\Http\Request;

/**
 * One asset with its current spec, files by role and the projects it's in.
 *
 * @mixin Asset
 */
class AssetDetailResource extends AssetResource
{
    public function toArray(Request $request): array
    {
        return [
            ...parent::toArray($request),
            'spec' => $this->currentVersion->spec,
            'files' => $this->currentVersion->assetFiles->map(fn (AssetFile $assetFile) => [
                'role' => $assetFile->role,
                'file' => new FileResource($assetFile->file),
            ]),
            'projects' => $this->projectAssets->map(fn (ProjectAsset $projectAsset) => [
                'id' => $projectAsset->project_id,
                'name' => $projectAsset->project?->name,
                'pinned_version_id' => $projectAsset->pinned_version_id,
                'role' => $projectAsset->role,
            ]),
        ];
    }
}
