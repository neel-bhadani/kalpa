<?php

use App\Enums\TaskStatus;

// The transition map from the spec, written out independently of TaskStatus.
const ALLOWED_MOVES = [
    'draft' => ['in_progress', 'paused', 'archived'],
    'in_progress' => ['waiting_job', 'needs_review', 'paused', 'failed', 'completed'],
    'waiting_job' => ['in_progress', 'needs_review', 'paused', 'failed'],
    'needs_review' => ['in_progress', 'completed'],
    'paused' => ['in_progress', 'archived'],
    'failed' => ['in_progress', 'archived'],
    'completed' => ['in_progress', 'archived'],
    'archived' => ['paused'],
];

dataset('every status pair', function () {
    foreach (TaskStatus::cases() as $from) {
        foreach (TaskStatus::cases() as $to) {
            yield "{$from->value} → {$to->value}" => [$from->value, $to->value];
        }
    }
});

it('allows exactly the moves in the map', function (string $from, string $to) {
    $ada = owner();
    $task = makeTask(makeProject($ada), $ada);
    $task->forceFill(['status' => $from])->save();

    $response = actingIn($ada, workspaceOf($ada))->postJson("/api/v1/tasks/{$task->id}/status", ['to' => $to]);

    if (in_array($to, ALLOWED_MOVES[$from], true)) {
        $response->assertOk()->assertJsonPath('data.status', $to);
        expect($task->events()->where('type', 'status_changed')->sole())
            ->from_status->value->toBe($from)
            ->to_status->value->toBe($to);
    } else {
        $response->assertUnprocessable()->assertJsonValidationErrors('to');
        expect($task->refresh()->status->value)->toBe($from);
    }
})->with('every status pair');

it('sets started_at and completed_at, and bumps row_version', function () {
    $ada = owner();
    $task = makeTask(makeProject($ada), $ada);
    $move = fn (string $to) => actingIn($ada, workspaceOf($ada))->postJson("/api/v1/tasks/{$task->id}/status", ['to' => $to])->assertOk();

    $move('in_progress');
    $task->refresh();
    expect($task->started_at)->not->toBeNull()
        ->and($task->completed_at)->toBeNull()
        ->and($task->row_version)->toBe(2);

    $move('completed');
    expect($task->refresh()->completed_at)->not->toBeNull();

    $move('in_progress'); // reopen
    $task->refresh();
    expect($task->completed_at)->toBeNull()
        ->and($task->row_version)->toBe(4);
});

it('lists the allowed moves on the task', function () {
    $ada = owner();
    $task = makeTask(makeProject($ada), $ada);

    actingIn($ada, workspaceOf($ada))->getJson("/api/v1/tasks/{$task->id}")
        ->assertJsonPath('data.allowed_transitions', ALLOWED_MOVES['draft']);
});
