<?php

namespace App\Models;

use App\Enums\UploadStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['workspace_id', 'user_id', 'file_name', 'size_bytes', 'mime', 'sha256', 'part_size', 's3_upload_id', 'path', 'status', 'file_id', 'expires_at'])]
#[Hidden(['s3_upload_id', 'path'])]
class Upload extends Model
{
    use HasUuids;

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'part_size' => 'integer',
            'status' => UploadStatus::class,
            'expires_at' => 'datetime',
        ];
    }

    public function file(): BelongsTo
    {
        return $this->belongsTo(File::class);
    }

    public function partCount(): int
    {
        return max(1, (int) ceil($this->size_bytes / $this->part_size));
    }

    /**
     * Uploaded to tmp/<upload_id>/... and waiting for the hash check before it becomes a file.
     */
    public function isTemporary(): bool
    {
        return str_starts_with($this->path, 'tmp/');
    }

    /**
     * Still accepting parts.
     */
    public function isOpen(): bool
    {
        return $this->status === UploadStatus::Pending && $this->expires_at->isFuture();
    }
}
