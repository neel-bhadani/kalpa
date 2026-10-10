<?php

namespace App\Models;

use App\Enums\AiAssistant;
use App\Enums\AiSessionStatus;
use App\Enums\ComputeTarget;
use App\Enums\TaskMode;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['workspace_id', 'project_id', 'task_id', 'module', 'assistant', 'mode', 'status', 'brain_model_key', 'compute_target', 'plan', 'summary', 'created_by'])]
class AiSession extends Model
{
    use HasUuids, SoftDeletes;

    protected function casts(): array
    {
        return [
            'assistant' => AiAssistant::class,
            'mode' => TaskMode::class,
            'status' => AiSessionStatus::class,
            'compute_target' => ComputeTarget::class,
            'plan' => 'object',
        ];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(AiMessage::class, 'session_id');
    }

    public function steps(): HasMany
    {
        return $this->hasMany(AiStep::class, 'session_id');
    }
}
