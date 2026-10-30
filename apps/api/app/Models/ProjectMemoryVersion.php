<?php

namespace App\Models;

use App\Enums\ActorType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Append-only: the database rejects updates and deletes.
 */
#[Fillable(['workspace_id', 'project_memory_id', 'number', 'patch', 'created_by_type', 'created_by_id', 'ai_step_id'])]
class ProjectMemoryVersion extends Model
{
    use HasUuids;

    const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'number' => 'integer',
            // Kept as decoded JSON (objects), so empty {} values survive.
            'patch' => 'object',
            'created_by_type' => ActorType::class,
        ];
    }
}
