<?php

namespace App\Http\Resources;

use App\Core\Modules\ModuleRegistry;
use App\Enums\TaskStatus;
use App\Models\Task;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Task
 */
class TaskResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $module = app(ModuleRegistry::class)->find($this->module);

        return [
            'id' => $this->id,
            'project_id' => $this->project_id,
            'project_name' => $this->whenLoaded('project', fn () => $this->project->name),
            'module' => $this->module,
            // Null when the module is no longer installed.
            'module_name' => $module?->name(),
            'kind' => $this->kind,
            'kind_label' => $module?->taskKinds()[$this->kind] ?? null,
            'title' => $this->title,
            'status' => $this->status,
            // The statuses the UI may offer next.
            'allowed_transitions' => array_map(fn (TaskStatus $status) => $status->value, $this->status->allowedTransitions()),
            'mode' => $this->mode,
            'assigned_to' => $this->assigned_to,
            'parent_task_id' => $this->parent_task_id,
            'depends_on' => $this->whenLoaded('dependsOn', fn () => $this->dependsOn->pluck('id')),
            'depends_on_titles' => $this->whenLoaded('dependsOn', fn () => $this->dependsOn->pluck('title')),
            // Checkpoints can be large: only sent on single-task routes, not in lists.
            'checkpoint' => $this->when($request->route('task') !== null, fn () => $this->checkpoint),
            'has_unsynced_changes' => $this->has_unsynced_changes,
            'progress' => $this->progress,
            'row_version' => $this->row_version,
            'started_at' => $this->started_at,
            'completed_at' => $this->completed_at,
            'created_by' => $this->created_by,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
