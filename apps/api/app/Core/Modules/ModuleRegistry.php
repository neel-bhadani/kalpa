<?php

namespace App\Core\Modules;

use App\Models\ModuleSetting;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * All installed modules, loaded from config/modules.php.
 * The only way the core reaches module code.
 */
class ModuleRegistry
{
    /** @var array<string, ModuleManifest> keyed by module key, in config order */
    private array $modules = [];

    /**
     * @param  list<class-string<ModuleManifest>>  $manifestClasses
     */
    public function __construct(array $manifestClasses)
    {
        foreach ($manifestClasses as $class) {
            $manifest = new $class;

            if (! $manifest instanceof ModuleManifest) {
                throw new InvalidArgumentException("[{$class}] must implement ModuleManifest.");
            }

            if (isset($this->modules[$manifest->key()])) {
                throw new InvalidArgumentException("Module key [{$manifest->key()}] is used twice.");
            }

            $this->modules[$manifest->key()] = $manifest;
        }
    }

    /**
     * @return array<string, ModuleManifest>
     */
    public function all(): array
    {
        return $this->modules;
    }

    public function find(string $key): ?ModuleManifest
    {
        return $this->modules[$key] ?? null;
    }

    /**
     * Whether each module is on in the workspace. Modules are on unless a setting turns them off.
     *
     * @return array<string, bool>
     */
    public function enabledMap(string $workspaceId): array
    {
        $disabled = ModuleSetting::where('workspace_id', $workspaceId)
            ->where('enabled', false)
            ->pluck('module')
            ->all();

        return array_map(fn (ModuleManifest $module) => ! in_array($module->key(), $disabled, true), $this->modules);
    }

    public function isEnabled(string $workspaceId, string $key): bool
    {
        return $this->enabledMap($workspaceId)[$key] ?? false;
    }

    /**
     * A task may only use an installed, enabled module and one of its kinds.
     *
     * @throws ValidationException
     */
    public function ensureTaskKind(string $workspaceId, string $moduleKey, string $kind): void
    {
        $module = $this->find($moduleKey);

        if ($module === null) {
            throw ValidationException::withMessages(['module' => "There is no \"{$moduleKey}\" module."]);
        }

        if (! $this->isEnabled($workspaceId, $moduleKey)) {
            throw ValidationException::withMessages(['module' => "The {$module->name()} module is turned off for this workspace."]);
        }

        if (! array_key_exists($kind, $module->taskKinds())) {
            throw ValidationException::withMessages(['kind' => "\"{$kind}\" is not a task kind of the {$module->name()} module."]);
        }
    }
}
