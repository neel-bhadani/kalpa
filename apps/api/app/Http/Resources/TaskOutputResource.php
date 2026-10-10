<?php

namespace App\Http\Resources;

use App\Models\TaskOutput;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin TaskOutput
 */
class TaskOutputResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'role' => $this->role,
            'asset' => new AssetResource($this->asset),
            'created_at' => $this->created_at,
        ];
    }
}
