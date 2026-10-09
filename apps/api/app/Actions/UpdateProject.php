<?php

namespace App\Actions;

use App\Models\Project;

class UpdateProject
{
    /**
     * @param  array{name?: string, description?: string|null, style?: string, aspect_ratio?: string, status?: string}  $changes
     */
    public function handle(Project $project, array $changes): Project
    {
        $project->fill($changes)->save();

        return $project;
    }
}
