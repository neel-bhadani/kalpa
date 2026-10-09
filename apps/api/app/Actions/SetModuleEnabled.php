<?php

namespace App\Actions;

use App\Models\ModuleSetting;
use App\Models\Workspace;

class SetModuleEnabled
{
    /**
     * Turn a module on or off for the workspace. The caller checks the module exists.
     */
    public function handle(Workspace $workspace, string $moduleKey, bool $enabled): ModuleSetting
    {
        return ModuleSetting::updateOrCreate(
            ['workspace_id' => $workspace->id, 'module' => $moduleKey],
            ['enabled' => $enabled],
        );
    }
}
