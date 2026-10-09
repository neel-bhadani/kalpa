<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

it('writes an event for every task change', function () {
    $ada = owner();
    $workspace = workspaceOf($ada);
    $task = makeTask(makeProject($ada), $ada);

    actingIn($ada, $workspace)->patchJson("/api/v1/tasks/{$task->id}", ['title' => 'Better title', 'mode' => 'semi'])->assertOk();
    actingIn($ada, $workspace)->postJson("/api/v1/tasks/{$task->id}/status", ['to' => 'in_progress'])->assertOk();
    actingIn($ada, $workspace)->putJson("/api/v1/tasks/{$task->id}/checkpoint", ['base_version' => 3, 'checkpoint' => ['scene' => 1]])->assertOk();

    actingIn($ada, $workspace)->getJson("/api/v1/tasks/{$task->id}/events")
        ->assertOk()
        ->assertJsonPath('data.*.type', ['created', 'updated', 'status_changed', 'checkpoint_saved'])
        // jsonb reorders keys, so check them one by one.
        ->assertJsonPath('data.1.data.changes.title', 'Better title')
        ->assertJsonPath('data.1.data.changes.mode', 'semi')
        ->assertJsonPath('data.1.actor_type', 'user')
        ->assertJsonPath('data.1.actor_id', $ada->id);
});

it('does not let events be changed or removed', function () {
    $ada = owner();
    $task = makeTask(makeProject($ada), $ada);

    expect(fn () => DB::transaction(fn () => DB::table('task_events')->where('task_id', $task->id)->update(['type' => 'updated'])))
        ->toThrow(QueryException::class, 'append-only');

    expect(fn () => DB::transaction(fn () => DB::table('task_events')->where('task_id', $task->id)->delete()))
        ->toThrow(QueryException::class, 'append-only');
});
