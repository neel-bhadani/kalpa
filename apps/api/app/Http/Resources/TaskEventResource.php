<?php

namespace App\Http\Resources;

use App\Models\TaskEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin TaskEvent
 */
class TaskEventResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'task_id' => $this->task_id,
            'type' => $this->type,
            'from_status' => $this->from_status,
            'to_status' => $this->to_status,
            'actor_type' => $this->actor_type,
            'actor_id' => $this->actor_id,
            'data' => $this->data,
            'created_at' => $this->created_at,
        ];
    }
}
