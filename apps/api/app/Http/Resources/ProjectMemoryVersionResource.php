<?php

namespace App\Http\Resources;

use App\Models\ProjectMemoryVersion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ProjectMemoryVersion
 */
class ProjectMemoryVersionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'number' => $this->number,
            'patch' => $this->patch,
            'created_by_type' => $this->created_by_type,
            'created_by_id' => $this->created_by_id,
            'ai_step_id' => $this->ai_step_id,
            'created_at' => $this->created_at,
        ];
    }
}
