<?php

it('adds and removes dependencies', function () {
    $ada = owner();
    $project = makeProject($ada);
    [$a, $b] = [makeTask($project, $ada, 'A'), makeTask($project, $ada, 'B')];

    actingIn($ada, workspaceOf($ada))->postJson("/api/v1/tasks/{$a->id}/dependencies", ['depends_on_task_id' => $b->id])
        ->assertOk()
        ->assertJsonPath('data.depends_on', [$b->id]);

    // Adding it again changes nothing.
    actingIn($ada, workspaceOf($ada))->postJson("/api/v1/tasks/{$a->id}/dependencies", ['depends_on_task_id' => $b->id])->assertOk();

    actingIn($ada, workspaceOf($ada))->deleteJson("/api/v1/tasks/{$a->id}/dependencies", ['depends_on_task_id' => $b->id])
        ->assertOk()
        ->assertJsonPath('data.depends_on', []);

    expect($a->events()->pluck('type')->map->value->all())
        ->toBe(['created', 'dependency_added', 'dependency_removed']);
});

it('rejects a dependency cycle', function () {
    $ada = owner();
    $project = makeProject($ada);
    [$a, $b, $c] = [makeTask($project, $ada, 'A'), makeTask($project, $ada, 'B'), makeTask($project, $ada, 'C')];
    $depend = fn ($task, $on) => actingIn($ada, workspaceOf($ada))->postJson("/api/v1/tasks/{$task->id}/dependencies", ['depends_on_task_id' => $on->id]);

    $depend($a, $b)->assertOk();
    $depend($b, $c)->assertOk();

    $depend($c, $a)->assertUnprocessable()->assertJsonValidationErrors(['depends_on_task_id' => 'cycle']);
    $depend($b, $a)->assertUnprocessable();
});

it('rejects a task depending on itself or on another project', function () {
    $ada = owner();
    $a = makeTask(makeProject($ada, name: 'One'), $ada);
    $other = makeTask(makeProject($ada, name: 'Two'), $ada);

    actingIn($ada, workspaceOf($ada))->postJson("/api/v1/tasks/{$a->id}/dependencies", ['depends_on_task_id' => $a->id])
        ->assertUnprocessable();
    actingIn($ada, workspaceOf($ada))->postJson("/api/v1/tasks/{$a->id}/dependencies", ['depends_on_task_id' => $other->id])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['depends_on_task_id' => 'same project']);
});
