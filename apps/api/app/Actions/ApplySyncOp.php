<?php

namespace App\Actions;

use App\Core\Exceptions\VersionConflict;
use App\Core\Sync\ChangeRecorder;
use App\Core\Sync\SyncPayloads;
use App\Core\Workspaces\CurrentWorkspace;
use App\Enums\TaskMode;
use App\Models\ProjectMemory;
use App\Models\SyncOp;
use App\Models\Task;
use App\Models\User;
use App\Models\UserDevice;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Apply one op a device made (often offline). The result is stored with the device and op_id,
 * in the same transaction as the change, so sending the op again returns the same result.
 *
 * Result: { op_id, status: applied, row_version } | { op_id, status: conflict, server }
 *       | { op_id, status: rejected, errors }
 */
class ApplySyncOp
{
    public function __construct(
        private CurrentWorkspace $current,
        private SaveTaskCheckpoint $saveCheckpoint,
        private UpdateTask $updateTask,
        private PatchProjectMemory $patchMemory,
        private SyncPayloads $payloads,
        private ChangeRecorder $changes,
    ) {}

    /**
     * @param  object{op_id: string, type: string, entity_id: string, base_row_version: int, payload: object}  $op
     * @return array<string, mixed>
     */
    public function handle(UserDevice $device, User $user, object $op): array
    {
        if ($stored = $this->stored($device, $op->op_id)) {
            return $stored;
        }

        try {
            return DB::transaction(function () use ($device, $user, $op) {
                $result = ['op_id' => $op->op_id, ...$this->apply($user, $op)];

                SyncOp::create([
                    'workspace_id' => $this->current->id(),
                    'device_id' => $device->id,
                    'op_id' => $op->op_id,
                    'result' => $result,
                ]);

                return $result;
            });
        } catch (UniqueConstraintViolationException) {
            // The same op arrived twice at once; the other request applied it.
            return $this->stored($device, $op->op_id);
        }
    }

    private function stored(UserDevice $device, string $opId): ?array
    {
        return SyncOp::where('device_id', $device->id)->where('op_id', $opId)->value('result');
    }

    /**
     * @return array<string, mixed>
     */
    private function apply(User $user, object $op): array
    {
        if (! $this->current->canWrite()) {
            return $this->rejected(['op' => ['You can only read this workspace.']]);
        }

        try {
            return match ($op->type) {
                'task.checkpoint' => $this->checkpoint($user, $op),
                'task.update' => $this->updateTask($user, $op),
                'project_memory.patch' => $this->patchMemory($user, $op),
                default => $this->rejected(['type' => ["Unknown op type \"{$op->type}\"."]]),
            };
        } catch (VersionConflict $conflict) {
            return [
                'status' => 'conflict',
                'server' => [...$this->payloads->one($conflict->current), 'last_change' => $this->payloads->lastChange($conflict->current)],
            ];
        } catch (ValidationException $e) {
            return $this->rejected($e->errors());
        }
    }

    private function checkpoint(User $user, object $op): array
    {
        $task = $this->find(Task::class, $op->entity_id);
        $checkpoint = $op->payload->checkpoint ?? null;

        if (! is_object($checkpoint)) {
            throw ValidationException::withMessages(['payload.checkpoint' => 'The checkpoint must be a JSON object.']);
        }

        // Saving a checkpoint also clears has_unsynced_changes.
        $task = $this->saveCheckpoint->handle($task, $op->base_row_version, $checkpoint, $user);

        return ['status' => 'applied', 'row_version' => $task->row_version];
    }

    private function updateTask(User $user, object $op): array
    {
        $task = $this->find(Task::class, $op->entity_id);
        $changes = Validator::make((array) $op->payload, [
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'mode' => ['sometimes', Rule::enum(TaskMode::class)],
        ])->validate();

        $task = $this->updateTask->handle($task, $user, $changes, $op->base_row_version);
        $this->clearUnsynced($task);

        return ['status' => 'applied', 'row_version' => $task->row_version];
    }

    private function patchMemory(User $user, object $op): array
    {
        $memory = $this->find(ProjectMemory::class, $op->entity_id);
        $patch = $op->payload->patch ?? null;

        if (! is_array($patch) || $patch === []) {
            throw ValidationException::withMessages(['payload.patch' => 'The patch must be a non-empty list of operations.']);
        }

        $memory = $this->patchMemory->handle($memory, $op->base_row_version, $patch, $user);

        return ['status' => 'applied', 'row_version' => $memory->row_version];
    }

    /**
     * The device's edits for this task arrived, so other devices no longer need to show "Unsaved".
     */
    private function clearUnsynced(Task $task): void
    {
        if ($task->has_unsynced_changes) {
            $task->forceFill(['has_unsynced_changes' => false])->save();
            $this->changes->upsert($task);
        }
    }

    /**
     * @template T of Model
     *
     * @param  class-string<T>  $model
     * @return T
     */
    private function find(string $model, string $id): Model
    {
        return $model::where('workspace_id', $this->current->id())->find($id)
            ?? throw ValidationException::withMessages(['entity_id' => 'Not found in this workspace.']);
    }

    private function rejected(array $errors): array
    {
        return ['status' => 'rejected', 'errors' => $errors];
    }
}
