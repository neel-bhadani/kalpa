<?php

namespace App\Http\Resources;

use App\Models\Job;
use App\Models\JobLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Job
 */
class JobResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'target' => $this->target,
            'status' => $this->status,
            'priority' => $this->priority,
            'progress' => $this->progress,
            'task_id' => $this->task_id,
            'task_title' => $this->whenLoaded('task', fn () => $this->task?->title),
            'worker' => $this->whenLoaded('worker', fn () => $this->worker ? [
                'id' => $this->worker->id, 'name' => $this->worker->name, 'kind' => $this->worker->kind,
            ] : null),
            'model_key' => $this->model_key,
            'input' => $this->input,
            'output' => $this->output,
            'error' => $this->error,
            'attempts' => $this->attempts,
            'max_attempts' => $this->max_attempts,
            'lease_until' => $this->lease_until,
            'cost_seconds' => $this->cost_seconds,
            'queued_at' => $this->queued_at,
            'started_at' => $this->started_at,
            'finished_at' => $this->finished_at,
            'created_at' => $this->created_at,
            'logs' => $this->whenLoaded('logs', fn () => $this->logs->map(fn (JobLog $log) => [
                'level' => $log->level, 'message' => $log->message, 'data' => $log->data, 'created_at' => $log->created_at,
            ])),
        ];
    }
}
