<?php

namespace App\Models;

use App\Core\Database\TextArray;
use App\Enums\AssetOrigin;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['workspace_id', 'kind', 'module', 'name', 'origin', 'licence', 'attribution', 'tags'])]
#[Hidden(['search'])]
class Asset extends Model
{
    use HasUuids, SoftDeletes;

    protected function casts(): array
    {
        return [
            'origin' => AssetOrigin::class,
            'tags' => TextArray::class,
        ];
    }

    public function versions(): HasMany
    {
        return $this->hasMany(AssetVersion::class);
    }

    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(AssetVersion::class, 'current_version_id');
    }

    public function projectAssets(): HasMany
    {
        return $this->hasMany(ProjectAsset::class);
    }

    public function linksFrom(): HasMany
    {
        return $this->hasMany(AssetLink::class, 'from_asset_id');
    }

    public function linksTo(): HasMany
    {
        return $this->hasMany(AssetLink::class, 'to_asset_id');
    }
}
