<?php

namespace App\Models;

use App\Enums\WorkerKind;
use App\Enums\WorkerStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['workspace_id', 'user_id', 'user_device_id', 'kind', 'name', 'capabilities', 'pairing_token_hash', 'status', 'last_heartbeat_at'])]
#[Hidden(['pairing_token_hash'])]
class ComputeWorker extends Model
{
    use HasUuids, SoftDeletes;

    protected function casts(): array
    {
        return [
            'kind' => WorkerKind::class,
            'status' => WorkerStatus::class,
            'capabilities' => 'array',
            'last_heartbeat_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Browser tabs run "device" jobs; local workers, the desktop app and pools run "worker" jobs.
     */
    public function jobTarget(): string
    {
        return $this->kind === WorkerKind::Browser ? 'device' : 'worker';
    }
}
