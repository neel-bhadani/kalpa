<?php

namespace App\Models;

use App\Enums\DevicePlatform;
use App\Enums\DeviceTier;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['user_id', 'client_id', 'platform', 'name', 'capabilities', 'tier', 'tier_override', 'last_seen_at'])]
#[Hidden(['user_id', 'deleted_at'])]
class UserDevice extends Model
{
    use HasUuids, SoftDeletes;

    protected function casts(): array
    {
        return [
            'platform' => DevicePlatform::class,
            'capabilities' => 'array',
            'tier' => DeviceTier::class,
            'tier_override' => DeviceTier::class,
            'last_seen_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
