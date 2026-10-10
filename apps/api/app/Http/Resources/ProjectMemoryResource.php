<?php

namespace App\Http\Resources;

use App\Models\ProjectMemory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ProjectMemory
 */
class ProjectMemoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'project_id' => $this->project_id,
            'data' => $this->data,
            'row_version' => $this->row_version,
            'updated_at' => $this->updated_at,
        ];
    }

    /**
     * Always wrap in { data }. Laravel skips the wrapper when the resource
     * already has a "data" key, and project memory does.
     */
    public function toResponse($request): JsonResponse
    {
        return response()->json(['data' => $this->resolve($request)]);
    }
}
