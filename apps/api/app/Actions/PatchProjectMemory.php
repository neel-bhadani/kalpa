<?php

namespace App\Actions;

use App\Core\Exceptions\VersionConflict;
use App\Core\Sync\ChangeRecorder;
use App\Enums\ActorType;
use App\Models\ProjectMemory;
use App\Models\ProjectMemoryVersion;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Swaggest\JsonDiff\Exception as JsonDiffException;
use Swaggest\JsonDiff\JsonPatch;

class PatchProjectMemory
{
    public function __construct(private ChangeRecorder $changes) {}

    /**
     * Apply RFC 6902 operations made against $baseVersion, and record them as a new version.
     *
     * @param  list<object>  $operations  decoded as objects, so {} values stay objects
     *
     * @throws VersionConflict when the memory changed since $baseVersion
     * @throws ValidationException when the patch can't be applied
     */
    public function handle(ProjectMemory $memory, int $baseVersion, array $operations, User $user): ProjectMemory
    {
        return DB::transaction(function () use ($memory, $baseVersion, $operations, $user) {
            $memory = ProjectMemory::whereKey($memory->id)->lockForUpdate()->firstOrFail();

            if ($memory->row_version !== $baseVersion) {
                throw new VersionConflict($memory);
            }

            $data = $memory->data;

            try {
                JsonPatch::import($operations)->apply($data);
            } catch (JsonDiffException $e) {
                throw ValidationException::withMessages(['patch' => "The patch could not be applied: {$e->getMessage()}"]);
            }

            $memory->data = $data;
            $memory->row_version++;
            $memory->save();

            ProjectMemoryVersion::create([
                'workspace_id' => $memory->workspace_id,
                'project_memory_id' => $memory->id,
                'number' => $memory->row_version,
                'patch' => $operations,
                'created_by_type' => ActorType::User,
                'created_by_id' => $user->id,
            ]);
            $this->changes->upsert($memory);

            return $memory;
        });
    }
}
