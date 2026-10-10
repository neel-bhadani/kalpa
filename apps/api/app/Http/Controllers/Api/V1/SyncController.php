<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\ApplySyncOp;
use App\Actions\MarkTasksUnsynced;
use App\Core\Sync\CurrentDevice;
use App\Core\Sync\SyncPayloads;
use App\Core\Workspaces\CurrentWorkspace;
use App\Enums\ChangeOp;
use App\Http\Controllers\Controller;
use App\Models\ChangeLog;
use App\Models\UserDevice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

/**
 * Offline sync: devices pull changes after a seq and push the ops they made.
 */
class SyncController extends Controller
{
    /**
     * GET sync?after=<seq>&limit=500. Each changed row once (its latest state), or a tombstone.
     */
    public function pull(Request $request, CurrentWorkspace $current, SyncPayloads $payloads): JsonResponse
    {
        $request->validate([
            'after' => ['sometimes', 'integer', 'min:0'],
            'limit' => ['sometimes', 'integer', 'between:1,500'],
        ]);
        $after = (int) $request->query('after', 0);
        $limit = (int) $request->query('limit', 500);

        $rows = ChangeLog::where('workspace_id', $current->id())
            ->where('seq', '>', $after)
            ->orderBy('seq')
            ->limit($limit + 1)
            ->get();

        $hasMore = $rows->count() > $limit;
        $rows = $rows->take($limit);

        // A row changed several times in this page is sent once, at its last change.
        $latest = $rows->keyBy(fn (ChangeLog $row) => $row->entity->value.':'.$row->entity_id)->sortBy('seq');

        $byEntity = [];
        foreach ($latest->groupBy(fn (ChangeLog $row) => $row->entity->value) as $type => $changes) {
            $byEntity[$type] = $payloads->many($changes->first()->entity, $current->id(), $changes->pluck('entity_id')->all());
        }

        $data = $latest->values()->map(function (ChangeLog $row) use ($byEntity) {
            $payload = $row->op === ChangeOp::Delete ? null : ($byEntity[$row->entity->value][$row->entity_id] ?? null);

            return [
                'seq' => $row->seq,
                'entity' => $row->entity,
                'entity_id' => $row->entity_id,
                // Deleted, or gone since: a tombstone.
                'op' => $payload === null ? ChangeOp::Delete : ChangeOp::Upsert,
                'row_version' => $row->row_version,
                'payload' => $payload,
            ];
        });

        return response()->json([
            'data' => $data,
            'meta' => ['next' => $rows->last()?->seq ?? $after, 'has_more' => $hasMore],
        ]);
    }

    /**
     * POST sync { device_id, ops: [{ op_id, type, entity_id, base_row_version, payload }] }. Applied in order.
     */
    public function push(Request $request, CurrentDevice $currentDevice, ApplySyncOp $apply): JsonResponse
    {
        $request->validate([
            'device_id' => ['required', 'uuid'],
            'ops' => ['required', 'array', 'list', 'max:100'],
            'ops.*.op_id' => ['required', 'uuid', 'distinct'],
            'ops.*.type' => ['required', 'string', 'max:50'],
            'ops.*.entity_id' => ['required', 'uuid'],
            'ops.*.base_row_version' => ['required', 'integer', 'min:1'],
            'ops.*.payload' => ['present', 'array'],
        ]);

        $device = $this->device($request, $request->input('device_id'));
        $currentDevice->set($device);

        // Decoded as objects, so {} inside checkpoints and patches stays {}.
        $results = array_map(
            fn (object $op) => $apply->handle($device, $request->user(), $op),
            $this->jsonBody($request)->ops,
        );

        return response()->json(['data' => $results]);
    }

    /**
     * POST sync/unsynced { task_ids } — sent with fetch keepalive when a tab closes with unsent edits.
     */
    public function unsynced(Request $request, CurrentWorkspace $current, MarkTasksUnsynced $mark): Response
    {
        abort_unless($current->canWrite(), 403);

        $data = $request->validate([
            'task_ids' => ['required', 'array', 'list', 'max:200'],
            'task_ids.*' => ['uuid'],
        ]);

        $mark->handle($current->id(), $data['task_ids']);

        return response()->noContent();
    }

    private function device(Request $request, string $id): UserDevice
    {
        return UserDevice::whereKey($id)->where('user_id', $request->user()->id)->first()
            ?? throw ValidationException::withMessages(['device_id' => 'This is not one of your devices. Register it first (POST /api/v1/devices).']);
    }
}
