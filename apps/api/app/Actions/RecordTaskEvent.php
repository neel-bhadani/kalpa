<?php

namespace App\Actions;

use App\Enums\ActorType;
use App\Enums\TaskEventType;
use App\Enums\TaskStatus;
use App\Models\Task;
use App\Models\TaskEvent;
use App\Models\User;

class RecordTaskEvent
{
    /**
     * Append one entry to the task's history. Call inside the same transaction as the change.
     * A null actor means the system (e.g. a job finishing).
     *
     * @param  array<string, mixed>  $data
     */
    public function handle(
        Task $task,
        TaskEventType $type,
        ?User $actor,
        array $data = [],
        ?TaskStatus $from = null,
        ?TaskStatus $to = null,
    ): TaskEvent {
        return TaskEvent::create([
            'workspace_id' => $task->workspace_id,
            'task_id' => $task->id,
            'type' => $type,
            'from_status' => $from,
            'to_status' => $to,
            // Assistants act through later steps (P0 step 9).
            'actor_type' => $actor ? ActorType::User : ActorType::System,
            'actor_id' => $actor?->id,
            'data' => (object) $data,
        ]);
    }
}
