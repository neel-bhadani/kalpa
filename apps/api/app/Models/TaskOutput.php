<?php

namespace App\Models;

use App\Enums\TaskOutputRole;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['workspace_id', 'task_id', 'asset_id', 'role'])]
class TaskOutput extends Model
{
    use HasUuids;

    protected function casts(): array
    {
        return ['role' => TaskOutputRole::class];
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }
}
