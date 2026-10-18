<?php

namespace App\Modules\Story\Models;

use App\Modules\Story\ScriptLineType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A line of a script asset's current version (read-only copy; see ScriptProjector).
 */
#[Fillable(['workspace_id', 'asset_id', 'asset_version_id', 'scene_id', 'spec_id', 'position', 'type', 'character_spec_id', 'text', 'emotion'])]
class ScriptLine extends Model
{
    use HasUuids;

    protected function casts(): array
    {
        return ['type' => ScriptLineType::class, 'position' => 'integer'];
    }

    public function scene(): BelongsTo
    {
        return $this->belongsTo(ScriptScene::class, 'scene_id');
    }
}
