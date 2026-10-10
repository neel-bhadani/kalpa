<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['workspace_id', 'sha256', 'size_bytes', 'mime', 'storage_disk', 'path', 'width', 'height', 'duration_ms', 'created_by'])]
class File extends Model
{
    use HasUuids;

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'duration_ms' => 'integer',
            'ref_count' => 'integer',
            'verified_at' => 'datetime',
        ];
    }

    /**
     * The 512 px WebP made by the file.thumbnail job, once it exists.
     */
    public function thumb(): HasOne
    {
        return $this->hasOne(FileDerivative::class, 'source_file_id')->where('kind', 'thumb');
    }

    /**
     * Where the bytes live: ws/<workspace_id>/<aa>/<bb>/<sha256>.
     */
    public static function pathFor(string $workspaceId, string $sha256): string
    {
        return sprintf('ws/%s/%s/%s/%s', $workspaceId, substr($sha256, 0, 2), substr($sha256, 2, 2), $sha256);
    }
}
