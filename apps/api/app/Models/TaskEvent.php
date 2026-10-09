<?php

namespace App\Models;

use App\Enums\ActorType;
use App\Enums\TaskEventType;
use App\Enums\TaskStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Append-only: the database rejects updates and deletes.
 */
#[Fillable(['workspace_id', 'task_id', 'type', 'from_status', 'to_status', 'actor_type', 'actor_id', 'data'])]
class TaskEvent extends Model
{
    use HasUuids;

    const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'type' => TaskEventType::class,
            'from_status' => TaskStatus::class,
            'to_status' => TaskStatus::class,
            'actor_type' => ActorType::class,
            'data' => 'object',
        ];
    }
}
