<?php

it('saves a checkpoint and bumps row_version', function () {
    $ada = owner();
    $task = makeTask(makeProject($ada), $ada);

    actingIn($ada, workspaceOf($ada))->putJson("/api/v1/tasks/{$task->id}/checkpoint", [
        'base_version' => 1,
        'checkpoint' => ['cursor' => 42, 'draft' => 'Once upon a time', 'meta' => new stdClass],
    ])
        ->assertOk()
        ->assertJsonPath('data.row_version', 2)
        ->assertJsonPath('data.checkpoint.cursor', 42);

    expect(actingIn($ada, workspaceOf($ada))->get("/api/v1/tasks/{$task->id}")->getContent())
        ->toContain('"meta":{}');
});

it('returns 409 with the server copy for a stale checkpoint', function () {
    $ada = owner();
    $task = makeTask(makeProject($ada), $ada);
    $save = fn (array $checkpoint) => actingIn($ada, workspaceOf($ada))->putJson("/api/v1/tasks/{$task->id}/checkpoint", [
        'base_version' => 1,
        'checkpoint' => $checkpoint,
    ]);

    $save(['draft' => 'from laptop'])->assertOk();

    $save(['draft' => 'from phone'])
        ->assertConflict()
        ->assertJsonPath('data.row_version', 2)
        ->assertJsonPath('data.checkpoint.draft', 'from laptop');
});

it('requires the checkpoint to be an object', function () {
    $ada = owner();
    $task = makeTask(makeProject($ada), $ada);

    actingIn($ada, workspaceOf($ada))->putJson("/api/v1/tasks/{$task->id}/checkpoint", [
        'base_version' => 1,
        'checkpoint' => [1, 2, 3],
    ])->assertUnprocessable()->assertJsonValidationErrors('checkpoint');
});
