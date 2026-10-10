<?php

namespace App\Models;

use App\Enums\AssetRelation;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['workspace_id', 'from_asset_id', 'to_asset_id', 'relation', 'from_version_id', 'data'])]
class AssetLink extends Model
{
    use HasUuids;

    protected function casts(): array
    {
        return ['relation' => AssetRelation::class, 'data' => 'object'];
    }

    public function fromAsset(): BelongsTo
    {
        return $this->belongsTo(Asset::class, 'from_asset_id');
    }

    public function toAsset(): BelongsTo
    {
        return $this->belongsTo(Asset::class, 'to_asset_id');
    }
}
