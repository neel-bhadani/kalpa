<?php

namespace App\Models;

use App\Enums\AssetFileRole;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A file used by an asset version, in a role. Rows are only inserted, never updated.
 */
#[Fillable(['workspace_id', 'asset_version_id', 'file_id', 'role'])]
class AssetFile extends Model
{
    use HasUuids;

    const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['role' => AssetFileRole::class];
    }

    public function file(): BelongsTo
    {
        return $this->belongsTo(File::class);
    }
}
