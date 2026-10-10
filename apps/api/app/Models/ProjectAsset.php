<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['workspace_id', 'project_id', 'asset_id', 'pinned_version_id', 'role'])]
class ProjectAsset extends Model
{
    use HasUuids;

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function pinnedVersion(): BelongsTo
    {
        return $this->belongsTo(AssetVersion::class, 'pinned_version_id');
    }
}
