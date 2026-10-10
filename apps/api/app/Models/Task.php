<?php

namespace App\Models;

use App\Enums\TaskAssignee;
use App\Enums\TaskMode;
use App\Enums\TaskStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['workspace_id', 'project_id', 'module', 'kind', 'title', 'mode', 'assigned_to', 'parent_task_id', 'created_by'])]
class Task extends Model
{
    use HasUuids, SoftDeletes;

    protected function casts(): array
    {
        return [
            'status' => TaskStatus::class,
            'mode' => TaskMode::class,
            'assigned_to' => TaskAssignee::class,
            // An object, so {} stays {} (an array cast would turn it into []).
            'checkpoint' => 'object',
            'has_unsynced_changes' => 'boolean',
            'progress' => 'integer',
            'row_version' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Tasks this one waits for.
     */
    public function dependsOn(): BelongsToMany
    {
        return $this->belongsToMany(Task::class, 'task_dependencies', 'task_id', 'depends_on_task_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(TaskEvent::class);
    }

    public function outputs(): HasMany
    {
        return $this->hasMany(TaskOutput::class);
    }
}
