<?php

use App\Enums\WorkspaceRole;
use App\Events\SyncNudge;
use App\Models\ChangeLog;
use App\Models\SyncOp;
use App\Models\User;
use App\Models\UserDevice;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

function deviceOf(User $user, string $name = 'Laptop'): UserDevice
{
    return UserDevice::create([
        'user_id' => $user->id, 'client_id' => (string) Str::uuid(), 'platform' => 'web',
        'name' => $name, 'capabilities' => [], 'tier' => 'mid',
    ]);
}

function op(string $type, string $entityId, int $base, array $payload, ?string $opId = null): array
{
    return ['op_id' => $opId ?? (string) Str::uuid(), 'type' => $type, 'entity_id' => $entityId, 'base_row_version' => $base, 'payload' => $payload];
}

function push(User $user, UserDevice $device, array $ops)
{
    return actingIn($user, workspaceOf($user))->postJson('/api/v1/sync', ['device_id' => $device->id, 'ops' => $ops]);
}

it('pulls changes in pages, each row once at its latest state', function () {
    $ada = owner();
    $project = makeProject($ada); // project + project_memory
    $a = makeTask($project, $ada, 'A');
    makeTask($project, $ada, 'B');
    actingIn($ada, workspaceOf($ada))->patchJson("/api/v1/tasks/{$a->id}", ['title' => 'A2']); // A again

    $first = actingIn($ada, workspaceOf($ada))->getJson('/api/v1/sync?after=0&limit=3')
        ->assertOk()
        ->assertJsonPath('meta.has_more', true)
        ->assertJsonPath('data.*.entity', ['project', 'project_memory', 'task']);

    $rest = actingIn($ada, workspaceOf($ada))->getJson('/api/v1/sync?after='.$first->json('meta.next'))
        ->assertJsonPath('meta.has_more', false)
        ->json('data');

    // Task A was changed twice in this page; it comes once, with its latest title and checkpoint.
    expect(collect($rest)->pluck('payload.title')->all())->toBe(['B', 'A2'])
        ->and($rest[1])->toHaveKey('payload.checkpoint');

    // Nothing new after the end.
    $next = actingIn($ada, workspaceOf($ada))->getJson('/api/v1/sync?after=0')->json('meta.next');
    actingIn($ada, workspaceOf($ada))->getJson("/api/v1/sync?after={$next}")->assertJsonCount(0, 'data')->assertJsonPath('meta.next', $next);
});

it('sends tombstones for deleted rows', function () {
    $ada = owner();
    $task = makeTask(makeProject($ada), $ada);
    $after = ChangeLog::max('seq');

    actingIn($ada, workspaceOf($ada))->deleteJson("/api/v1/tasks/{$task->id}")->assertNoContent();

    actingIn($ada, workspaceOf($ada))->getJson("/api/v1/sync?after={$after}")
        ->assertJsonPath('data.0.entity', 'task')
        ->assertJsonPath('data.0.entity_id', $task->id)
        ->assertJsonPath('data.0.op', 'delete')
        ->assertJsonPath('data.0.payload', null);
});

it('only shows changes of the current workspace, to its members', function () {
    $ada = owner();
    $bob = owner('Bob');
    makeTask(makeProject($ada), $ada);
    makeProject($bob, name: 'Bobs');

    $bobs = actingIn($bob, workspaceOf($bob))->getJson('/api/v1/sync')->json('data.*.payload.name');
    expect(array_filter($bobs))->toBe(['Bobs']);

    actingIn($bob, workspaceOf($ada))->getJson('/api/v1/sync')->assertForbidden();
    // Without the workspace header (the test client keeps headers between requests, so drop them).
    $this->flushHeaders()->actingAs($bob)->getJson('/api/v1/sync')->assertStatus(400);
});

it('applies an op once, however often it is sent', function () {
    $ada = owner();
    $device = deviceOf($ada);
    $task = makeTask(makeProject($ada), $ada);
    $checkpoint = op('task.checkpoint', $task->id, 1, ['checkpoint' => ['data' => ['notes' => 'Hello']]]);

    push($ada, $device, [$checkpoint])->assertOk()->assertJsonPath('data.0.status', 'applied')->assertJsonPath('data.0.row_version', 2);
    push($ada, $device, [$checkpoint])->assertOk()->assertJsonPath('data.0.status', 'applied')->assertJsonPath('data.0.row_version', 2);

    expect($task->refresh()->row_version)->toBe(2)
        ->and($task->checkpoint->data->notes)->toBe('Hello')
        ->and(SyncOp::count())->toBe(1)
        ->and(ChangeLog::where('entity', 'task')->where('entity_id', $task->id)->latest('seq')->first()->device_id)->toBe($device->id);
});

it('returns the server copy on a conflict, with who changed it last', function () {
    $ada = owner();
    [$laptop, $phone] = [deviceOf($ada, 'Laptop'), deviceOf($ada, 'Phone')];
    $task = makeTask(makeProject($ada), $ada);

    push($ada, $phone, [op('task.checkpoint', $task->id, 1, ['checkpoint' => ['data' => ['notes' => 'From the phone']]])])
        ->assertJsonPath('data.0.status', 'applied');

    push($ada, $laptop, [op('task.checkpoint', $task->id, 1, ['checkpoint' => ['data' => ['notes' => 'From the laptop']]])])
        ->assertJsonPath('data.0.status', 'conflict')
        ->assertJsonPath('data.0.server.row_version', 2)
        ->assertJsonPath('data.0.server.checkpoint.data.notes', 'From the phone')
        ->assertJsonPath('data.0.server.last_change.device_name', 'Phone');

    expect($task->refresh()->checkpoint->data->notes)->toBe('From the phone');
});

it('applies ops in order', function () {
    $ada = owner();
    $device = deviceOf($ada);
    $task = makeTask(makeProject($ada), $ada);
    $memory = $task->project->memory;

    push($ada, $device, [
        op('task.checkpoint', $task->id, 1, ['checkpoint' => ['view' => ['tab' => 'notes'], 'data' => []]]),
        op('task.update', $task->id, 2, ['title' => 'Renamed offline', 'mode' => 'semi']),
        op('project_memory.patch', $memory->id, 1, ['patch' => [['op' => 'add', 'path' => '/style/look', 'value' => 'noir']]]),
        op('task.update', $task->id, 3, ['mode' => 'turbo']),
    ])
        ->assertJsonPath('data.*.status', ['applied', 'applied', 'applied', 'rejected'])
        ->assertJsonPath('data.1.row_version', 3)
        ->assertJsonStructure(['data' => [3 => ['errors' => ['mode']]]]);

    expect($task->refresh())->title->toBe('Renamed offline')->row_version->toBe(3)
        ->and($memory->refresh()->data->style->look)->toBe('noir');

    // The same two task ops in the other order: the second is based on a version that doesn't exist yet.
    $other = makeTask($task->project, $ada, 'Other');
    push($ada, $device, [
        op('task.update', $other->id, 2, ['title' => 'Too early']),
        op('task.checkpoint', $other->id, 1, ['checkpoint' => ['data' => []]]),
    ])->assertJsonPath('data.*.status', ['conflict', 'applied']);
});

it('rejects ops from readers and for rows of other workspaces', function () {
    $ada = owner();
    $task = makeTask(makeProject($ada), $ada);
    $viewer = memberOf(workspaceOf($ada), WorkspaceRole::Viewer);

    actingIn($viewer, workspaceOf($ada))->postJson('/api/v1/sync', ['device_id' => deviceOf($viewer)->id, 'ops' => [op('task.update', $task->id, 1, ['title' => 'x'])]])
        ->assertJsonPath('data.0.status', 'rejected');

    $bob = owner('Bob');
    push($bob, deviceOf($bob), [op('task.update', $task->id, 1, ['title' => 'x'])])
        ->assertJsonPath('data.0.status', 'rejected')
        ->assertJsonPath('data.0.errors.entity_id.0', 'Not found in this workspace.');

    // A device of someone else is refused outright.
    actingIn($ada, workspaceOf($ada))->postJson('/api/v1/sync', ['device_id' => deviceOf($bob)->id, 'ops' => [op('task.update', $task->id, 1, ['title' => 'x'])]])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('device_id');
});

it('flags tasks with unsent edits, and clears the flag when their ops arrive', function () {
    $ada = owner();
    $device = deviceOf($ada);
    $task = makeTask(makeProject($ada), $ada);

    actingIn($ada, workspaceOf($ada))->postJson('/api/v1/sync/unsynced', ['task_ids' => [$task->id]])->assertNoContent();
    expect($task->refresh()->has_unsynced_changes)->toBeTrue();

    push($ada, $device, [op('task.checkpoint', $task->id, 1, ['checkpoint' => ['data' => ['notes' => 'Finally sent']]])])
        ->assertJsonPath('data.0.status', 'applied');
    expect($task->refresh()->has_unsynced_changes)->toBeFalse();

    // The same for a task.update op.
    actingIn($ada, workspaceOf($ada))->postJson('/api/v1/sync/unsynced', ['task_ids' => [$task->id]]);
    push($ada, $device, [op('task.update', $task->id, 2, ['title' => 'Done'])])->assertJsonPath('data.0.status', 'applied');
    expect($task->refresh()->has_unsynced_changes)->toBeFalse();
});

it('nudges other devices on the project channel', function () {
    Event::fake([SyncNudge::class]);
    $ada = owner();
    $project = makeProject($ada);

    makeTask($project, $ada);

    Event::assertDispatched(SyncNudge::class, fn (SyncNudge $nudge) => $nudge->projectIds === [$project->id]
        && $nudge->broadcastOn()[0]->name === "private-project.{$project->id}");
});

it('does not let change_log rows be changed or removed', function () {
    $ada = owner();
    makeProject($ada);

    expect(fn () => DB::transaction(fn () => ChangeLog::query()->update(['op' => 'delete'])))->toThrow(QueryException::class, 'append-only');
    expect(fn () => DB::transaction(fn () => ChangeLog::query()->delete()))->toThrow(QueryException::class, 'append-only');
});

/*
| Every Action that changes a synced row writes change_log (entity and op).
*/

dataset('changing actions', [
    'create project' => [fn ($ada, $w, $p, $t, $a) => makeProject($ada, name: 'New'), [['project', 'upsert'], ['project_memory', 'upsert']]],
    'update project' => [fn ($ada, $w, $p) => actingIn($ada, $w)->patchJson("/api/v1/projects/{$p->id}", ['name' => 'X']), [['project', 'upsert']]],
    'open project' => [fn ($ada, $w, $p) => actingIn($ada, $w)->postJson("/api/v1/projects/{$p->id}/open"), [['project', 'upsert']]],
    'delete project' => [fn ($ada, $w, $p) => actingIn($ada, $w)->deleteJson("/api/v1/projects/{$p->id}"), [['task', 'delete'], ['project', 'delete']]],
    'patch memory' => [fn ($ada, $w, $p) => actingIn($ada, $w)->patchJson("/api/v1/projects/{$p->id}/memory", ['base_version' => 1, 'patch' => [['op' => 'add', 'path' => '/style/x', 'value' => 1]]]), [['project_memory', 'upsert']]],
    'create task' => [fn ($ada, $w, $p) => makeTask($p, $ada, 'New'), [['task', 'upsert']]],
    'update task' => [fn ($ada, $w, $p, $t) => actingIn($ada, $w)->patchJson("/api/v1/tasks/{$t->id}", ['title' => 'X']), [['task', 'upsert']]],
    'change status' => [fn ($ada, $w, $p, $t) => actingIn($ada, $w)->postJson("/api/v1/tasks/{$t->id}/status", ['to' => 'in_progress']), [['task', 'upsert']]],
    'save checkpoint' => [fn ($ada, $w, $p, $t) => actingIn($ada, $w)->putJson("/api/v1/tasks/{$t->id}/checkpoint", ['base_version' => 1, 'checkpoint' => ['a' => 1]]), [['task', 'upsert']]],
    'add dependency' => [fn ($ada, $w, $p, $t) => actingIn($ada, $w)->postJson("/api/v1/tasks/{$t->id}/dependencies", ['depends_on_task_id' => makeTask($p, $ada, 'Dep')->id]), [['task', 'upsert'], ['task', 'upsert']]],
    'delete task' => [fn ($ada, $w, $p, $t) => actingIn($ada, $w)->deleteJson("/api/v1/tasks/{$t->id}"), [['task', 'delete']]],
    'mark unsynced' => [fn ($ada, $w, $p, $t) => actingIn($ada, $w)->postJson('/api/v1/sync/unsynced', ['task_ids' => [$t->id]]), [['task', 'upsert']]],
    'create asset' => [fn ($ada, $w, $p, $t) => actingIn($ada, $w)->postJson('/api/v1/assets', ['kind' => 'story', 'name' => 'S', 'spec' => ['title' => 'S'], 'project_id' => $p->id, 'task_id' => $t->id]),
        [['asset_version', 'upsert'], ['asset', 'upsert'], ['project_asset', 'upsert'], ['task_output', 'upsert']]],
    'new asset version' => [fn ($ada, $w, $p, $t, $a) => actingIn($ada, $w)->postJson("/api/v1/assets/{$a}/versions", ['base_version' => 1, 'spec' => ['title' => 'S2']]), [['asset_version', 'upsert'], ['asset', 'upsert']]],
    'restore version' => [fn ($ada, $w, $p, $t, $a) => actingIn($ada, $w)->postJson("/api/v1/assets/{$a}/restore/1"), [['asset_version', 'upsert'], ['asset', 'upsert']]],
    'update asset' => [fn ($ada, $w, $p, $t, $a) => actingIn($ada, $w)->patchJson("/api/v1/assets/{$a}", ['name' => 'Renamed']), [['asset', 'upsert']]],
    'delete asset' => [fn ($ada, $w, $p, $t, $a) => actingIn($ada, $w)->deleteJson("/api/v1/assets/{$a}"), [['asset', 'delete']]],
    'attach asset' => [fn ($ada, $w, $p, $t, $a) => actingIn($ada, $w)->postJson("/api/v1/projects/{$p->id}/assets", ['asset_id' => $a]), [['project_asset', 'upsert']]],
    'detach asset' => [function ($ada, $w, $p, $t, $a) {
        // Attaching is setup here; only the detach is checked.
        actingIn($ada, $w)->postJson("/api/v1/projects/{$p->id}/assets", ['asset_id' => $a]);

        return ['after' => ChangeLog::max('seq'), 'response' => actingIn($ada, $w)->deleteJson("/api/v1/projects/{$p->id}/assets", ['asset_id' => $a])];
    }, [['project_asset', 'delete']]],
]);

it('writes change_log for every change', function (Closure $action, array $expected) {
    $ada = owner();
    $workspace = workspaceOf($ada);
    $project = makeProject($ada);
    $task = makeTask($project, $ada);
    $asset = actingIn($ada, $workspace)->postJson('/api/v1/assets', ['kind' => 'story', 'name' => 'S', 'spec' => ['title' => 'S']])->json('data.id');
    $after = ChangeLog::max('seq');

    $result = $action($ada, $workspace, $project, $task, $asset);
    if (is_array($result) && isset($result['after'])) {
        $after = $result['after'];
        $result = $result['response'];
    }
    if ($result instanceof TestResponse) {
        $result->assertSuccessful();
    }

    $written = ChangeLog::where('seq', '>', $after)->orderBy('seq')->get()
        ->map(fn (ChangeLog $row) => [$row->entity->value, $row->op->value])
        ->all();

    expect($written)->toBe($expected);
})->with('changing actions');
