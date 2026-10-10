<?php

namespace App\Models;

use App\Enums\DerivativeKind;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * A file made from another one (proxy, thumbnail, LOD...). Filled by jobs in P0 step 7.
 */
#[Fillable(['workspace_id', 'source_file_id', 'kind', 'params', 'file_id'])]
class FileDerivative extends Model
{
    use HasUuids;

    protected function casts(): array
    {
        return ['kind' => DerivativeKind::class, 'params' => 'object'];
    }
}
