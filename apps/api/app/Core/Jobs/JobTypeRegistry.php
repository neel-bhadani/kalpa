<?php

namespace App\Core\Jobs;

use App\Core\DevTools;
use App\Core\Jobs\Handlers\MakeThumbnail;
use App\Core\Jobs\Handlers\VerifyFileHash;
use App\Core\Modules\ModuleRegistry;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Every job type: the core ones below plus those declared in module manifests (jobTypes()).
 */
class JobTypeRegistry
{
    /**
     * Core job types. "handler" runs server-target jobs; "dev_only" types only exist in local/testing.
     */
    public const CORE = [
        'file.verify_hash' => ['targets' => ['server'], 'capability' => null, 'handler' => VerifyFileHash::class],
        'file.thumbnail' => ['targets' => ['server'], 'capability' => null, 'handler' => MakeThumbnail::class],
        // Counts to N with progress, so the whole claim/heartbeat/complete loop can be tried.
        'demo.count' => ['targets' => ['device', 'worker'], 'capability' => null, 'dev_only' => true],
    ];

    /** @var array<string, array{targets: list<string>, capability: ?string, handler?: class-string<ServerJobHandler>, dev_only?: bool}> */
    private array $types = self::CORE;

    public function __construct(ModuleRegistry $modules)
    {
        foreach ($modules->all() as $module) {
            foreach ($module->jobTypes() as $type => $definition) {
                if (! str_starts_with($type, $module->key().'.')) {
                    throw new InvalidArgumentException("Job type [{$type}] of module [{$module->key()}] must start with \"{$module->key()}.\".");
                }
                if (isset($this->types[$type])) {
                    throw new InvalidArgumentException("Job type [{$type}] is declared twice.");
                }
                $this->types[$type] = $definition;
            }
        }

        if (! DevTools::enabled()) {
            $this->types = array_filter($this->types, fn (array $type) => ! ($type['dev_only'] ?? false));
        }
    }

    /**
     * @return array{targets: list<string>, capability: ?string, handler?: class-string<ServerJobHandler>, dev_only?: bool}|null
     */
    public function find(string $type): ?array
    {
        return $this->types[$type] ?? null;
    }

    /**
     * Check the type exists and can run on the target; returns the target (the type's first one if none given).
     *
     * @throws ValidationException
     */
    public function ensure(string $type, ?string $target): string
    {
        $definition = $this->find($type)
            ?? throw ValidationException::withMessages(['type' => "There is no \"{$type}\" job type."]);

        $target ??= $definition['targets'][0];

        if (! in_array($target, $definition['targets'], true)) {
            throw ValidationException::withMessages([
                'target' => "\"{$type}\" jobs run on: ".implode(', ', $definition['targets']).'.',
            ]);
        }

        return $target;
    }

    /**
     * Types a worker with these capabilities can run on a target.
     *
     * @param  list<string>  $capabilities
     * @return list<string>
     */
    public function claimable(string $target, array $capabilities): array
    {
        return array_keys(array_filter($this->types, fn (array $type) => in_array($target, $type['targets'], true)
            && ($type['capability'] === null || in_array($type['capability'], $capabilities, true))));
    }

    /**
     * @return class-string<ServerJobHandler>
     */
    public function handler(string $type): string
    {
        return $this->find($type)['handler'] ?? throw new InvalidArgumentException("Job type [{$type}] has no server handler.");
    }
}
