<?php

namespace App\Models;

use App\Enums\WorkspaceRole;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

#[Fillable(['workspace_id', 'user_id', 'role'])]
class WorkspaceMember extends Pivot
{
    use HasUuids;

    protected $table = 'workspace_members';

    protected function casts(): array
    {
        return [
            'role' => WorkspaceRole::class,
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
