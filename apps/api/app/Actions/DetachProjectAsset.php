<?php

namespace App\Actions;

use App\Models\Project;
use App\Models\ProjectAsset;

class DetachProjectAsset
{
    public function handle(Project $project, string $assetId): void
    {
        ProjectAsset::where('project_id', $project->id)->where('asset_id', $assetId)->delete();
    }
}
