<?php

namespace App\Actions;

use App\Core\Sync\ChangeRecorder;
use App\Models\Project;
use Illuminate\Support\Facades\DB;

class UpdateProject
{
    public function __construct(private ChangeRecorder $changes) {}

    /**
     * @param  array{name?: string, description?: string|null, style?: string, aspect_ratio?: string, status?: string}  $changes
     */
    public function handle(Project $project, array $changes): Project
    {
        return DB::transaction(function () use ($project, $changes) {
            $project->fill($changes)->save();
            $this->changes->upsert($project);

            return $project;
        });
    }
}
