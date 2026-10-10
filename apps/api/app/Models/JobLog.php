<?php

namespace App\Models;

use App\Enums\JobLogLevel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Append-only: the database rejects updates and deletes.
 */
#[Fillable(['workspace_id', 'job_id', 'level', 'message', 'data'])]
class JobLog extends Model
{
    use HasUuids;

    const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['level' => JobLogLevel::class, 'data' => 'object'];
    }
}
