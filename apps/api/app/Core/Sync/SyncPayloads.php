<?php

namespace App\Core\Sync;

use App\Enums\SyncEntity;
use App\Http\Resources\AiSessionResource;
use App\Http\Resources\AiStepResource;
use App\Http\Resources\AssetResource;
use App\Http\Resources\AssetVersionResource;
use App\Http\Resources\ProjectMemoryResource;
use App\Http\Resources\ProjectResource;
use App\Http\Resources\TaskResource;
use App\Models\AiSession;
use App\Models\AiStep;
use App\Models\Asset;
use App\Models\AssetVersion;
use App\Models\ChangeLog;
use App\Models\Project;
use App\Models\ProjectAsset;
use App\Models\ProjectMemory;
use App\Models\Task;
use App\Models\TaskOutput;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The current state of synced rows, as devices cache them. Null means "gone" (a tombstone).
 */
class SyncPayloads
{
    /**
     * @param  list<string>  $ids
     * @return array<string, array<string, mixed>|null> payload by id; missing or soft-deleted rows are null
     */
    public function many(SyncEntity $entity, string $workspaceId, array $ids): array
    {
        $rows = $this->query($entity)
            ->where('workspace_id', $workspaceId)
            ->whereKey($ids)
            ->get()
            ->keyBy(fn (Model $row) => $row->getKey());

        $payloads = [];
        foreach ($ids as $id) {
            $row = $rows[$id] ?? null;
            $gone = $row === null || (method_exists($row, 'trashed') && $row->trashed());
            $payloads[$id] = $gone ? null : $this->present($entity, $row);
        }

        return $payloads;
    }

    public function one(Model $model): ?array
    {
        $entity = ChangeRecorder::entityOf($model);

        return $this->many($entity, $model->getAttribute('workspace_id'), [$model->getKey()])[$model->getKey()];
    }

    /**
     * Who changed a row last, for the conflict dialog.
     *
     * @return array{at: ?string, device_name: ?string}
     */
    public function lastChange(Model $model): array
    {
        $change = ChangeLog::where('entity', ChangeRecorder::entityOf($model))
            ->where('entity_id', $model->getKey())
            ->with('device')
            ->orderByDesc('seq')
            ->first();

        return ['at' => $change?->created_at?->toJSON(), 'device_name' => $change?->device?->name];
    }

    private function query(SyncEntity $entity): Builder
    {
        return match ($entity) {
            SyncEntity::Project => Project::withTrashed(),
            SyncEntity::Task => Task::withTrashed()->with('dependsOn'),
            SyncEntity::Asset => Asset::withTrashed()->with('currentVersion'),
            SyncEntity::AssetVersion => AssetVersion::query()->with(['creator', 'assetFiles']),
            SyncEntity::ProjectMemory => ProjectMemory::query(),
            SyncEntity::ProjectAsset => ProjectAsset::query(),
            SyncEntity::TaskOutput => TaskOutput::query(),
            SyncEntity::AiSession => AiSession::withTrashed(),
            SyncEntity::AiStep => AiStep::query(),
        };
    }

    private function present(SyncEntity $entity, Model $row): array
    {
        $request = request();

        return match ($entity) {
            SyncEntity::Project => (new ProjectResource($row))->resolve($request),
            // Tasks carry their checkpoint: that's what lets another device resume.
            SyncEntity::Task => [...(new TaskResource($row))->resolve($request), 'checkpoint' => $row->checkpoint],
            SyncEntity::Asset => (new AssetResource($row))->resolve($request),
            SyncEntity::AssetVersion => [...(new AssetVersionResource($row))->resolve($request), 'asset_id' => $row->asset_id],
            SyncEntity::ProjectMemory => (new ProjectMemoryResource($row))->resolve($request),
            SyncEntity::ProjectAsset => $row->only(['id', 'project_id', 'asset_id', 'pinned_version_id', 'role', 'updated_at']),
            SyncEntity::TaskOutput => [...$row->only(['id', 'task_id', 'asset_id', 'updated_at']), 'role' => $row->role->value],
            SyncEntity::AiSession => (new AiSessionResource($row))->resolve($request),
            SyncEntity::AiStep => (new AiStepResource($row))->resolve($request),
        };
    }
}
