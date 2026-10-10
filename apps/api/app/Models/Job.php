<?php

namespace App\Models;

use App\Enums\JobPriority;
use App\Enums\JobStatus;
use App\Enums\JobTarget;
use App\Events\JobUpdated;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Kalpa's own job (heavy or AI work), not a Laravel queue job.
 */
#[Fillable(['workspace_id', 'task_id', 'ai_step_id', 'created_by', 'type', 'target', 'status', 'priority', 'worker_id', 'model_key',
    'input', 'output', 'idempotency_key', 'progress', 'attempts', 'max_attempts', 'error', 'lease_until', 'cost_seconds',
    'queued_at', 'started_at', 'finished_at'])]
class Job extends Model
{
    use HasUuids;

    protected function casts(): array
    {
        return [
            'target' => JobTarget::class,
            'status' => JobStatus::class,
            'priority' => JobPriority::class,
            'input' => 'object',
            'output' => 'object',
            'progress' => 'integer',
            'attempts' => 'integer',
            'max_attempts' => 'integer',
            'cost_seconds' => 'integer',
            'lease_until' => 'datetime',
            'queued_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // Every change goes out live (status, progress) once the transaction commits.
        static::saved(fn (Job $job) => JobUpdated::dispatch($job));
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function worker(): BelongsTo
    {
        return $this->belongsTo(ComputeWorker::class, 'worker_id')->withTrashed();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function logs(): HasMany
    {
        return $this->hasMany(JobLog::class);
    }
}
