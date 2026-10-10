<?php

namespace App\Core\Modules;

/**
 * What every module tells the core about itself. One class per module:
 * app/Modules/<Name>/Manifest.php, listed in config/modules.php.
 */
interface ModuleManifest
{
    /** Lowercase key used in URLs and tasks.module, e.g. "story". */
    public function key(): string;

    /** Display name, e.g. "Story & Script". */
    public function name(): string;

    /** A lucide icon name, e.g. "book-open". */
    public function icon(): string;

    public function version(): string;

    /**
     * Task kinds this module handles: key => label.
     *
     * @return array<string, string>
     */
    public function taskKinds(): array;

    /**
     * Asset kinds this module owns: key => label. Each kind has a JSON Schema at
     * packages/schemas/<kind>.schema.json. A kind belongs to exactly one module (or the core).
     *
     * @return array<string, string>
     */
    public function assetKinds(): array;

    /**
     * Job types this module runs: key ("<module>.<name>") => ['targets' => [...], 'capability' => ?string].
     * targets: device, worker and/or server. capability: what a worker must report to claim it (e.g. "gpu").
     *
     * @return array<string, array{targets: list<string>, capability: ?string}>
     */
    public function jobTypes(): array;
}
