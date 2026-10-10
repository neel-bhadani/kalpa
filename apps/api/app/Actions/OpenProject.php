<?php

namespace App\Actions;

use App\Core\Sync\ChangeRecorder;
use App\Models\Project;
use Illuminate\Support\Facades\DB;

class OpenProject
{
    public function __construct(private ChangeRecorder $changes) {}

    /**
     * Note when the project was last opened. Opening is not an edit, so updated_at stays.
     */
    public function handle(Project $project): Project
    {
        return DB::transaction(function () use ($project) {
            $project->timestamps = false;
            $project->forceFill(['last_opened_at' => now()])->save();
            $project->timestamps = true;
            $this->changes->upsert($project);

            return $project;
        });
    }
}
