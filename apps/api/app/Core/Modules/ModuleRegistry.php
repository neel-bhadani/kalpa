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
    /**
     * Generic asset kinds owned by the core; any module may create them.
     */
    public const CORE_ASSET_KINDS = [
        'image' => 'Image',
        'audio' => 'Audio',
        'video' => 'Video',
        'file' => 'File',
    ];

    /** Owner key used for core asset kinds. */
    public const CORE = 'core';

    /** @var array<string, ModuleManifest> keyed by module key, in config order */
    private array $modules = [];

    /** @var array<string, array{owner: string, label: string}> asset kind => owner module key (or "core") */
    private array $assetKinds = [];

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

        foreach (self::CORE_ASSET_KINDS as $kind => $label) {
            $this->assetKinds[$kind] = ['owner' => self::CORE, 'label' => $label];
        }

        foreach ($this->modules as $module) {
            foreach ($module->assetKinds() as $kind => $label) {
                if (isset($this->assetKinds[$kind])) {
                    $owner = $this->assetKinds[$kind]['owner'];
                    throw new InvalidArgumentException("Asset kind [{$kind}] is claimed by both [{$owner}] and [{$module->key()}].");
                }

                $this->assetKinds[$kind] = ['owner' => $module->key(), 'label' => $label];
            }
        }
    }

    /**
     * Every asset kind with its owner ("core" or a module key) and label.
     *
     * @return array<string, array{owner: string, label: string}>
     */
    public function assetKinds(): array
    {
        return $this->assetKinds;
    }

    /**
     * The module key (or "core") that owns an asset kind; null for an unknown kind.
     */
    public function assetKindOwner(string $kind): ?string
    {
        return $this->assetKinds[$kind]['owner'] ?? null;
    }

    /**
     * An asset may only use a known kind whose owning module is enabled (core kinds always are).
     *
     * @throws ValidationException
     */
    public function ensureAssetKind(string $workspaceId, string $kind): string
    {
        $owner = $this->assetKindOwner($kind);

        if ($owner === null) {
            throw ValidationException::withMessages(['kind' => "There is no \"{$kind}\" asset kind."]);
        }

        if ($owner !== self::CORE && ! $this->isEnabled($workspaceId, $owner)) {
            throw ValidationException::withMessages(['kind' => "The {$this->modules[$owner]->name()} module is turned off for this workspace."]);
        }

        return $owner;
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
