<?php

use App\Enums\TaskStatus;

it('pages projects with a cursor', function () {
    $ada = owner();
    foreach (['One', 'Two', 'Three'] as $name) {
        makeProject($ada, name: $name);
        $this->travel(1)->seconds();
    }

    $first = actingIn($ada, workspaceOf($ada))->getJson('/api/v1/projects?per_page=2')
        ->assertOk()
        ->assertJsonMissingPath('links')
        ->assertJsonPath('data.*.name', ['Three', 'Two']);

    $cursor = $first->json('meta.next_cursor');
    expect($cursor)->not->toBeNull();

    actingIn($ada, workspaceOf($ada))->getJson("/api/v1/projects?per_page=2&cursor={$cursor}")
        ->assertOk()
        ->assertJsonPath('data.*.name', ['One'])
        ->assertJsonPath('meta.next_cursor', null);
});

it('filters tasks by project, status and module', function () {
    $ada = owner();
    $film = makeProject($ada, name: 'Film');
    $ad = makeProject($ada, name: 'Ad');
    $outline = makeTask($film, $ada, 'Outline');
    makeTask($ad, $ada, 'Ad script');
    $outline->forceFill(['status' => TaskStatus::InProgress])->save();

    actingIn($ada, workspaceOf($ada))->getJson("/api/v1/tasks?project={$film->id}")->assertJsonPath('data.*.title', ['Outline']);
    actingIn($ada, workspaceOf($ada))->getJson('/api/v1/tasks?status=in_progress')->assertJsonPath('data.*.title', ['Outline']);
    actingIn($ada, workspaceOf($ada))->getJson('/api/v1/tasks?module=voice')->assertJsonCount(0, 'data');
});
