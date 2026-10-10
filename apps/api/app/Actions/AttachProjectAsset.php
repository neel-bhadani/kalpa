<?php

namespace App\Actions;

use App\Core\Sync\ChangeRecorder;
use App\Models\Asset;
use App\Models\Project;
use App\Models\ProjectAsset;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AttachProjectAsset
{
    public function __construct(private ChangeRecorder $changes) {}

    /**
     * Add the asset to the project, or change its pin. A null pin follows the latest version.
     */
    public function handle(Project $project, Asset $asset, ?string $pinnedVersionId, ?string $role): ProjectAsset
    {
        if ($project->workspace_id !== $asset->workspace_id) {
            throw ValidationException::withMessages(['asset_id' => 'The asset must be in the same workspace as the project.']);
        }

        if ($pinnedVersionId !== null && ! $asset->versions()->whereKey($pinnedVersionId)->exists()) {
            throw ValidationException::withMessages(['pinned_version_id' => 'That version belongs to another asset.']);
        }

        return DB::transaction(function () use ($project, $asset, $pinnedVersionId, $role) {
            $item = ProjectAsset::updateOrCreate(
                ['project_id' => $project->id, 'asset_id' => $asset->id],
                ['workspace_id' => $project->workspace_id, 'pinned_version_id' => $pinnedVersionId, 'role' => $role],
            );
            $this->changes->upsert($item);

            return $item;
        });
    }
}
