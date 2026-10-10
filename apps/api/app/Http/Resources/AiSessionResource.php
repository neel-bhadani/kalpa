<?php

namespace App\Http\Resources;

use App\Models\AiSession;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AiSession
 */
class AiSessionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'project_id' => $this->project_id,
            'task_id' => $this->task_id,
            'module' => $this->module,
            'assistant' => $this->assistant,
            'mode' => $this->mode,
            'status' => $this->status,
            'brain_model_key' => $this->brain_model_key,
            'compute_target' => $this->compute_target,
            'plan' => $this->plan,
            'summary' => $this->summary,
            'messages' => AiMessageResource::collection($this->whenLoaded('messages')),
            'steps' => AiStepResource::collection($this->whenLoaded('steps')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
