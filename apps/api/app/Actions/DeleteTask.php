<?php

namespace App\Actions;

use App\Enums\TaskEventType;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class DeleteTask
{
    public function __construct(private RecordTaskEvent $recordEvent) {}

    /**
     * Soft-delete the task and record it.
     *
     * @param  array<string, mixed>  $data
     */
    public function handle(Task $task, User $user, array $data = []): void
    {
        DB::transaction(function () use ($task, $user, $data) {
            $this->recordEvent->handle($task, TaskEventType::Deleted, $user, $data);
            $task->delete();
        });
    }
}
