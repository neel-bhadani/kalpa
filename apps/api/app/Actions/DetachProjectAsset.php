<?php

namespace App\Actions;

use App\Core\Sync\ChangeRecorder;
use App\Models\Project;
use App\Models\ProjectAsset;
use Illuminate\Support\Facades\DB;

class DetachProjectAsset
{
    public function __construct(private ChangeRecorder $changes) {}

    public function handle(Project $project, string $assetId): void
    {
        DB::transaction(function () use ($project, $assetId) {
            $item = ProjectAsset::where('project_id', $project->id)->where('asset_id', $assetId)->first();

            if ($item !== null) {
                $item->delete();
                $this->changes->delete($item);
            }
        });
    }
}
