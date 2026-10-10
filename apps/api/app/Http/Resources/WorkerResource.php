<?php

namespace App\Http\Resources;

use App\Models\ComputeWorker;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ComputeWorker
 */
class WorkerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind,
            'name' => $this->name,
            'status' => $this->status,
            'capabilities' => $this->capabilities,
            'user_device_id' => $this->user_device_id,
            'last_heartbeat_at' => $this->last_heartbeat_at,
            'created_at' => $this->created_at,
        ];
    }
}
