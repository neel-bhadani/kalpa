<?php

it('creates project memory at version 1 with the project', function () {
    $ada = owner();
    $project = makeProject($ada);

    actingIn($ada, workspaceOf($ada))->getJson("/api/v1/projects/{$project->id}/memory")
        ->assertOk()
        ->assertJsonPath('data.row_version', 1);

    // {} stays an object, [] stays an array.
    expect(actingIn($ada, workspaceOf($ada))->get("/api/v1/projects/{$project->id}/memory")->getContent())
        ->toContain('"data":{"style":{},"decisions":[],"story_bible":{}}');

    actingIn($ada, workspaceOf($ada))->getJson("/api/v1/projects/{$project->id}/memory/versions")
        ->assertJsonPath('data.0.number', 1);
});

it('applies a JSON Patch and records a version', function () {
    $ada = owner();
    $project = makeProject($ada);

    actingIn($ada, workspaceOf($ada))->patchJson("/api/v1/projects/{$project->id}/memory", [
        'base_version' => 1,
        'patch' => [
            ['op' => 'add', 'path' => '/story_bible/hero', 'value' => ['name' => 'Mira']],
            ['op' => 'add', 'path' => '/decisions/-', 'value' => 'Night scenes only'],
        ],
    ])
        ->assertOk()
        ->assertJsonPath('data.row_version', 2)
        ->assertJsonPath('data.data.story_bible.hero.name', 'Mira')
        ->assertJsonPath('data.data.decisions', ['Night scenes only']);

    actingIn($ada, workspaceOf($ada))->getJson("/api/v1/projects/{$project->id}/memory/versions")
        ->assertJsonPath('data.0.number', 2)
        ->assertJsonPath('data.0.patch.0.path', '/story_bible/hero')
        ->assertJsonPath('data.0.created_by_id', $ada->id)
        ->assertJsonCount(2, 'data');
});

it('keeps empty objects as objects, so later paths into them work', function () {
    $ada = owner();
    $project = makeProject($ada);

    actingIn($ada, workspaceOf($ada))->patchJson("/api/v1/projects/{$project->id}/memory", [
        'base_version' => 1,
        'patch' => [['op' => 'add', 'path' => '/style/palette', 'value' => new stdClass]],
    ])->assertOk();

    actingIn($ada, workspaceOf($ada))->patchJson("/api/v1/projects/{$project->id}/memory", [
        'base_version' => 2,
        'patch' => [['op' => 'add', 'path' => '/style/palette/main', 'value' => '#8B7CFF']],
    ])->assertOk()->assertJsonPath('data.data.style.palette.main', '#8B7CFF');
});

it('returns 409 with the current memory for a stale base version', function () {
    $ada = owner();
    $project = makeProject($ada);
    $patch = fn (int $base, string $look) => actingIn($ada, workspaceOf($ada))->patchJson("/api/v1/projects/{$project->id}/memory", [
        'base_version' => $base,
        'patch' => [['op' => 'add', 'path' => '/style/look', 'value' => $look]],
    ]);

    $patch(1, 'noir')->assertOk();

    $patch(1, 'pastel')
        ->assertConflict()
        ->assertJsonPath('data.row_version', 2)
        ->assertJsonPath('data.data.style.look', 'noir');

    expect($project->memory->versions()->count())->toBe(2);
});

it('rejects a patch that cannot be applied', function () {
    $ada = owner();
    $project = makeProject($ada);

    actingIn($ada, workspaceOf($ada))->patchJson("/api/v1/projects/{$project->id}/memory", [
        'base_version' => 1,
        'patch' => [['op' => 'test', 'path' => '/style', 'value' => 'something else']],
    ])->assertUnprocessable()->assertJsonValidationErrors('patch');

    expect($project->memory->refresh()->row_version)->toBe(1);
});
