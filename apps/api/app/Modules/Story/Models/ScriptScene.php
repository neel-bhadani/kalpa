<?php

namespace App\Modules\Story\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A scene of a script asset's current version (read-only copy; see ScriptProjector).
 */
#[Fillable(['workspace_id', 'asset_id', 'asset_version_id', 'spec_id', 'position', 'heading', 'location', 'time_of_day', 'summary'])]
class ScriptScene extends Model
{
    use HasUuids;

    protected function casts(): array
    {
        return ['position' => 'integer'];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(ScriptLine::class, 'scene_id')->orderBy('position');
    }
}
