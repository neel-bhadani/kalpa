<?php

namespace App\Http\Resources;

use App\Models\Upload;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Upload
 */
class UploadResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'file_name' => $this->file_name,
            'size_bytes' => $this->size_bytes,
            'mime' => $this->mime,
            'sha256' => $this->sha256,
            'part_size' => $this->part_size,
            'part_count' => $this->partCount(),
            'status' => $this->status,
            'file_id' => $this->file_id,
            'expires_at' => $this->expires_at,
            'created_at' => $this->created_at,
        ];
    }
}
