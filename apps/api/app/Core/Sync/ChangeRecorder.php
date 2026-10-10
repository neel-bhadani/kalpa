<?php

namespace App\Core\Sync;

use App\Enums\ChangeOp;
use App\Enums\SyncEntity;
use App\Events\SyncNudge;
use App\Models\Asset;
use App\Models\AssetVersion;
use App\Models\ChangeLog;
use App\Models\Project;
use App\Models\ProjectAsset;
use App\Models\ProjectMemory;
use App\Models\Task;
use App\Models\TaskOutput;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The one place that writes change_log. Actions call it inside the transaction that makes the change.
 *
 * Rows of a workspace are written one transaction at a time (an advisory lock held until commit),
 * so seq order is also commit order: a device that pulled up to seq N never misses a smaller seq
 * that commits later.
 */
class ChangeRecorder
{
    public function __construct(private CurrentDevice $device) {}

    public function upsert(Model $model): ChangeLog
    {
        return $this->write($model, ChangeOp::Upsert);
    }

    public function delete(Model $model): ChangeLog
    {
        return $this->write($model, ChangeOp::Delete);
    }

    private function write(Model $model, ChangeOp $op): ChangeLog
    {
        $workspaceId = $model->getAttribute('workspace_id');

        DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ["change_log:{$workspaceId}"]);

        $change = ChangeLog::create([
            'workspace_id' => $workspaceId,
            'entity' => self::entityOf($model),
            'entity_id' => $model->getKey(),
            'op' => $op,
            'row_version' => $model->getAttribute('row_version'),
            'device_id' => $this->device->id(),
        ]);

        // Other devices on these projects pull at once instead of at their next poll.
        $projectIds = $this->projectIdsOf($model);
        if ($projectIds !== []) {
            SyncNudge::dispatch($projectIds, $change->seq);
        }

        return $change;
    }

    public static function entityOf(Model $model): SyncEntity
    {
        return match (true) {
            $model instanceof Project => SyncEntity::Project,
            $model instanceof Task => SyncEntity::Task,
            $model instanceof Asset => SyncEntity::Asset,
            $model instanceof AssetVersion => SyncEntity::AssetVersion,
            $model instanceof ProjectMemory => SyncEntity::ProjectMemory,
            $model instanceof ProjectAsset => SyncEntity::ProjectAsset,
            $model instanceof TaskOutput => SyncEntity::TaskOutput,
            default => throw new InvalidArgumentException('Not a synced model: '.$model::class),
        };
    }

    /**
     * @return list<string>
     */
    private function projectIdsOf(Model $model): array
    {
        return match (true) {
            $model instanceof Project => [$model->id],
            $model instanceof Task, $model instanceof ProjectMemory, $model instanceof ProjectAsset => [$model->project_id],
            $model instanceof TaskOutput => array_filter([Task::withTrashed()->find($model->task_id)?->project_id]),
            $model instanceof Asset => ProjectAsset::where('asset_id', $model->id)->pluck('project_id')->all(),
            $model instanceof AssetVersion => ProjectAsset::where('asset_id', $model->asset_id)->pluck('project_id')->all(),
        };
    }
}
