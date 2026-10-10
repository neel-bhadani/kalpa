<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * The stored result of one pushed op (device_id + op_id), so retries don't apply twice.
 */
#[Fillable(['workspace_id', 'device_id', 'op_id', 'result'])]
class SyncOp extends Model
{
    use HasUuids;

    const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['result' => 'array'];
    }
}
