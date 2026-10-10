<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\SetModuleEnabled;
use App\Core\Modules\ModuleManifest;
use App\Core\Modules\ModuleRegistry;
use App\Core\Workspaces\CurrentWorkspace;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ModuleController extends Controller
{
    public function __construct(private ModuleRegistry $modules) {}

    public function index(CurrentWorkspace $current): JsonResponse
    {
        $enabled = $this->modules->enabledMap($current->id());

        $data = array_map(
            fn (ModuleManifest $module) => $this->present($module, $enabled[$module->key()]),
            array_values($this->modules->all()),
        );

        return response()->json(['data' => $data]);
    }

    public function update(Request $request, string $key, CurrentWorkspace $current, SetModuleEnabled $setEnabled): JsonResponse
    {
        Gate::authorize('manage-modules');

        $module = $this->modules->find($key);
        abort_if($module === null, 404);

        $data = $request->validate(['enabled' => ['required', 'boolean']]);

        $setting = $setEnabled->handle($current->get(), $key, $data['enabled']);

        return response()->json(['data' => $this->present($module, $setting->enabled)]);
    }

    private function present(ModuleManifest $module, bool $enabled): array
    {
        return [
            'key' => $module->key(),
            'name' => $module->name(),
            'icon' => $module->icon(),
            'version' => $module->version(),
            'task_kinds' => collect($module->taskKinds())
                ->map(fn (string $label, string $key) => ['key' => $key, 'label' => $label])
                ->values(),
            'asset_kinds' => collect($module->assetKinds())
                ->map(fn (string $label, string $key) => ['key' => $key, 'label' => $label])
                ->values(),
            'enabled' => $enabled,
        ];
    }
}
