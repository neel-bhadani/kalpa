<?php

namespace App\Models;

use App\Enums\AiRole;
use App\Events\AiMessageCreated;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only: the database rejects updates and deletes.
 */
#[Fillable(['workspace_id', 'session_id', 'role', 'content', 'tokens'])]
class AiMessage extends Model
{
    use HasUuids;

    const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['role' => AiRole::class, 'content' => 'object', 'tokens' => 'integer'];
    }

    protected static function booted(): void
    {
        static::created(fn (AiMessage $message) => AiMessageCreated::dispatch($message));
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(AiSession::class, 'session_id');
    }
}
