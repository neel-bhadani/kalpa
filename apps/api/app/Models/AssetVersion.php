<?php

namespace App\Models;

use App\Enums\ActorType;
use App\Events\AssetVersionCreated;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Append-only: the database rejects updates and deletes.
 */
#[Fillable(['workspace_id', 'asset_id', 'number', 'parent_version_id', 'schema_version', 'spec', 'patch', 'created_by_type', 'created_by_id', 'ai_step_id', 'message'])]
class AssetVersion extends Model
{
    use HasUuids;

    const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'number' => 'integer',
            'schema_version' => 'integer',
            // Objects, so {} stays {} and JSON Patch paths keep working.
            'spec' => 'object',
            'patch' => 'object',
            'created_by_type' => ActorType::class,
        ];
    }

    protected static function booted(): void
    {
        static::created(fn (AssetVersion $version) => AssetVersionCreated::dispatch($version));
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function assetFiles(): HasMany
    {
        return $this->hasMany(AssetFile::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }
}
