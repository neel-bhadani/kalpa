<?php

use App\Enums\TaskStatus;

// Tasks in several statuses across two projects; later ones are "updated" later.
function dashboardFixture(): array
{
    $ada = owner();
    $film = makeProject($ada, name: 'Film');
    $ad = makeProject($ada, name: 'Ad');

    $tasks = [];
    foreach ([
        ['Outline', $film, 'story', 'write_story', TaskStatus::Draft],
        ['Script', $film, 'story', 'write_script', TaskStatus::InProgress],
        ['Hero', $film, 'character', 'create_character', TaskStatus::NeedsReview],
        ['Old cut', $film, 'video', 'edit_video', TaskStatus::Completed],
        ['Jingle', $ad, 'music', 'create_song', TaskStatus::WaitingJob],
        ['Retired', $ad, 'ads', 'create_ad', TaskStatus::Archived],
        ['Voice', $ad, 'voice', 'record_dialogue', TaskStatus::Paused],
    ] as [$title, $project, $module, $kind, $status]) {
        test()->travel(1)->minutes();
        $task = makeTask($project, $ada, $title, $module, $kind);
        $task->forceFill(['status' => $status])->save();
        $tasks[$title] = $task;
    }

    return [$ada, $film, $ad, $tasks];
}

it('shows the last three open tasks to continue', function () {
    [$ada] = dashboardFixture();

    actingIn($ada, workspaceOf($ada))->getJson('/api/v1/dashboard')
        ->assertOk()
        // Newest first; "Retired" (archived) and "Old cut" (completed) are left out.
        ->assertJsonPath('data.continue.*.title', ['Voice', 'Jingle', 'Hero'])
        ->assertJsonPath('data.continue.0.project_name', 'Ad')
        ->assertJsonPath('data.continue.0.module_name', 'Voice')
        ->assertJsonPath('data.continue.0.kind_label', 'Record dialogue');
});

it('counts tasks by status', function () {
    [$ada, $film] = dashboardFixture();

    actingIn($ada, workspaceOf($ada))->getJson('/api/v1/dashboard')
        ->assertJsonPath('data.counts', [
            'draft' => 1, 'in_progress' => 1, 'waiting_job' => 1, 'needs_review' => 1,
            'paused' => 1, 'failed' => 0, 'completed' => 1, 'archived' => 1,
        ]);

    actingIn($ada, workspaceOf($ada))->getJson("/api/v1/dashboard?project={$film->id}")
        ->assertJsonPath('data.counts.completed', 1)
        ->assertJsonPath('data.counts.archived', 0)
        ->assertJsonPath('data.counts.waiting_job', 0);
});

it('lists tasks without archived ones, and filters them', function () {
    [$ada, $film] = dashboardFixture();
    $get = fn (string $query = '') => actingIn($ada, workspaceOf($ada))->getJson("/api/v1/dashboard{$query}");

    $get()->assertJsonPath('data.tasks.*.title', ['Voice', 'Jingle', 'Old cut', 'Hero', 'Script', 'Outline']);
    $get('?status=archived')->assertJsonPath('data.tasks.*.title', ['Retired']);
    $get('?status=draft,paused')->assertJsonPath('data.tasks.*.title', ['Voice', 'Outline']);
    $get('?module=story')->assertJsonPath('data.tasks.*.title', ['Script', 'Outline']);
    $get("?project={$film->id}&module=video")->assertJsonPath('data.tasks.*.title', ['Old cut']);
    $get('?status=sleeping')->assertUnprocessable();
});

it('pages the task list with a cursor', function () {
    [$ada] = dashboardFixture();

    $first = actingIn($ada, workspaceOf($ada))->getJson('/api/v1/dashboard?per_page=4')
        ->assertJsonCount(4, 'data.tasks');

    actingIn($ada, workspaceOf($ada))->getJson('/api/v1/dashboard?per_page=4&cursor='.$first->json('meta.next_cursor'))
        ->assertJsonPath('data.tasks.*.title', ['Script', 'Outline'])
        ->assertJsonPath('meta.next_cursor', null);
});
