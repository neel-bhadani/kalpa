<?php

namespace App\Actions;

use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class DeleteProject
{
    public function __construct(private DeleteTask $deleteTask) {}

    /**
     * Soft-delete the project and its tasks (each task records why).
     */
    public function handle(Project $project, User $user): void
    {
        DB::transaction(function () use ($project, $user) {
            foreach ($project->tasks()->lazyById() as $task) {
                $this->deleteTask->handle($task, $user, ['reason' => 'project_deleted']);
            }

            $project->delete();
        });
    }
}
