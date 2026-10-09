<?php

namespace App\Actions;

use App\Models\Project;

class OpenProject
{
    /**
     * Note when the project was last opened. Opening is not an edit, so updated_at stays.
     */
    public function handle(Project $project): Project
    {
        $project->timestamps = false;
        $project->forceFill(['last_opened_at' => now()])->save();
        $project->timestamps = true;

        return $project;
    }
}
