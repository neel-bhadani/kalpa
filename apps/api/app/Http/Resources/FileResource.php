<?php

namespace App\Http\Resources;

use App\Models\File;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin File
 */
class FileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'sha256' => $this->sha256,
            'size_bytes' => $this->size_bytes,
            'mime' => $this->mime,
            'width' => $this->width,
            'height' => $this->height,
            'duration_ms' => $this->duration_ms,
            'verified_at' => $this->verified_at,
            'created_at' => $this->created_at,
        ];
    }
}
