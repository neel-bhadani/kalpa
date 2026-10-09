<?php

namespace App\Models;

use App\Enums\AspectRatio;
use App\Enums\ProjectStatus;
use App\Enums\ProjectStyle;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['workspace_id', 'name', 'description', 'style', 'aspect_ratio', 'status', 'created_by'])]
class Project extends Model
{
    use HasUuids, SoftDeletes;

    protected function casts(): array
    {
        return [
            'style' => ProjectStyle::class,
            'aspect_ratio' => AspectRatio::class,
            'status' => ProjectStatus::class,
            'last_opened_at' => 'datetime',
        ];
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    public function memory(): HasOne
    {
        return $this->hasOne(ProjectMemory::class);
    }
}
