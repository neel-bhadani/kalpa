<?php

namespace App\Models;

use App\Enums\ChangeOp;
use App\Enums\SyncEntity;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only: the database rejects updates and deletes. Written by App\Core\Sync\ChangeRecorder.
 */
#[Fillable(['workspace_id', 'entity', 'entity_id', 'op', 'row_version', 'device_id'])]
class ChangeLog extends Model
{
    protected $table = 'change_log';

    protected $primaryKey = 'seq';

    const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'seq' => 'integer',
            'entity' => SyncEntity::class,
            'op' => ChangeOp::class,
            'row_version' => 'integer',
        ];
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(UserDevice::class, 'device_id');
    }
}
