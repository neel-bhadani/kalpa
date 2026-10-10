<?php

namespace App\Models;

use App\Enums\AiHandoffStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['workspace_id', 'from_session_id', 'to_session_id', 'task_id', 'request', 'result', 'status'])]
class AiHandoff extends Model
{
    use HasUuids;

    protected function casts(): array
    {
        return ['status' => AiHandoffStatus::class, 'request' => 'object', 'result' => 'object'];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }
}
