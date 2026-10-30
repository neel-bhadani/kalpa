<?php

namespace App\Http\Resources;

use App\Models\AiStep;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AiStep
 */
class AiStepResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'session_id' => $this->session_id,
            'seq' => $this->seq,
            'title' => $this->title,
            'reason' => $this->reason,
            'tool' => $this->tool,
            'args' => $this->args,
            'status' => $this->status,
            'allowed_transitions' => array_map(fn ($status) => $status->value, $this->status->allowedTransitions()),
            'asset_id' => $this->asset_id,
            'before' => $this->before,
            'after' => $this->after,
            'job_id' => $this->job_id,
            'error' => $this->error,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
