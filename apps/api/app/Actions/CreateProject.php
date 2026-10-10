<?php

namespace App\Actions;

use App\Core\Sync\ChangeRecorder;
use App\Enums\ActorType;
use App\Models\Project;
use App\Models\ProjectMemory;
use App\Models\ProjectMemoryVersion;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;

class CreateProject
{
    public function __construct(private ChangeRecorder $changes) {}

    /**
     * Create the project with its empty project memory (version 1).
     *
     * @param  array{name: string, description?: string|null, style?: string, aspect_ratio?: string}  $attributes
     */
    public function handle(Workspace $workspace, User $user, array $attributes): Project
    {
        return DB::transaction(function () use ($workspace, $user, $attributes) {
            $project = Project::create([
                ...$attributes,
                'workspace_id' => $workspace->id,
                'created_by' => $user->id,
            ]);

            $memory = ProjectMemory::create([
                'workspace_id' => $workspace->id,
                'project_id' => $project->id,
            ])->refresh();

            // Version 1 holds the starting document, so the history can be replayed from the start.
            ProjectMemoryVersion::create([
                'workspace_id' => $workspace->id,
                'project_memory_id' => $memory->id,
                'number' => $memory->row_version,
                'patch' => [['op' => 'replace', 'path' => '', 'value' => $memory->data]],
                'created_by_type' => ActorType::User,
                'created_by_id' => $user->id,
            ]);

            $project->refresh();
            $this->changes->upsert($project);
            $this->changes->upsert($memory);

            return $project;
        });
    }
}
