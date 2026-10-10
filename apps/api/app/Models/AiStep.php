<?php

namespace App\Models;

use App\Enums\AiStepStatus;
use App\Events\AiStepUpdated;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['workspace_id', 'session_id', 'seq', 'title', 'tool', 'args', 'status', 'asset_id', 'before', 'after',
    'before_version_id', 'after_version_id', 'job_id', 'preview_file_id', 'error'])]
class AiStep extends Model
{
    use HasUuids;

    protected function casts(): array
    {
        return [
            'seq' => 'integer',
            'status' => AiStepStatus::class,
            'args' => 'object',
            'before' => 'object',
            'after' => 'object',
        ];
    }

    protected static function booted(): void
    {
        static::saved(fn (AiStep $step) => AiStepUpdated::dispatch($step));
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(AiSession::class, 'session_id');
    }
}
