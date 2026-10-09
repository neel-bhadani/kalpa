<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['workspace_id', 'project_id', 'data'])]
class ProjectMemory extends Model
{
    use HasUuids;

    protected $table = 'project_memory';

    protected function casts(): array
    {
        return [
            // An object, so {} stays {} and JSON Patch paths keep working.
            'data' => 'object',
            'row_version' => 'integer',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function versions(): HasMany
    {
        return $this->hasMany(ProjectMemoryVersion::class);
    }
}
