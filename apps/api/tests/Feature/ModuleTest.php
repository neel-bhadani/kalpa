<?php

use App\Core\Modules\ModuleManifest;
use App\Core\Modules\ModuleRegistry;
use App\Enums\WorkspaceRole;

it('loads the ten modules in order', function () {
    $modules = app(ModuleRegistry::class)->all();

    expect(array_keys($modules))->toBe(['story', 'character', 'assets3d', 'cgi', 'voice', 'music', 'video', 'ads', 'player', 'io'])
        ->and($modules)->each->toBeInstanceOf(ModuleManifest::class)
        ->and($modules['video']->taskKinds())->toHaveKeys(['create_video', 'edit_video', 'upscale_video'])
        ->and(app(ModuleRegistry::class)->find('nope'))->toBeNull();
});

it('lists modules with their enabled state', function () {
    $ada = owner();

    actingIn($ada, workspaceOf($ada))->getJson('/api/v1/modules')
        ->assertOk()
        ->assertJsonCount(10, 'data')
        ->assertJsonPath('data.0.key', 'story')
        ->assertJsonPath('data.0.name', 'Story & Script')
        ->assertJsonPath('data.0.icon', 'book-open')
        ->assertJsonPath('data.0.task_kinds.1', ['key' => 'write_script', 'label' => 'Write script'])
        ->assertJsonPath('data.0.enabled', true);
});

it('lets only the owner turn a module on or off', function () {
    $ada = owner();
    $workspace = workspaceOf($ada);
    $editor = memberOf($workspace, WorkspaceRole::Editor);

    actingIn($editor, $workspace)->patchJson('/api/v1/modules/music', ['enabled' => false])->assertForbidden();

    actingIn($ada, $workspace)->patchJson('/api/v1/modules/music', ['enabled' => false])
        ->assertOk()
        ->assertJsonPath('data.enabled', false);
    actingIn($ada, $workspace)->getJson('/api/v1/modules')->assertJsonPath('data.5.enabled', false);

    actingIn($ada, $workspace)->patchJson('/api/v1/modules/music', ['enabled' => true])->assertJsonPath('data.enabled', true);
    actingIn($ada, $workspace)->patchJson('/api/v1/modules/nope', ['enabled' => false])->assertNotFound();
});

it('rejects an unknown module, a wrong kind and a disabled module', function () {
    $ada = owner();
    $workspace = workspaceOf($ada);
    $project = makeProject($ada);
    $create = fn (string $module, string $kind) => actingIn($ada, $workspace)->postJson("/api/v1/projects/{$project->id}/tasks", [
        'module' => $module, 'kind' => $kind, 'title' => 'Task',
    ]);

    $create('cooking', 'write_story')->assertUnprocessable()->assertJsonValidationErrors(['module' => 'no "cooking" module']);
    $create('story', 'create_song')->assertUnprocessable()->assertJsonValidationErrors(['kind' => 'not a task kind']);
    $create('music', 'create_song')->assertCreated();

    $song = makeTask($project, $ada, 'Theme', 'music', 'create_song');
    actingIn($ada, $workspace)->patchJson('/api/v1/modules/music', ['enabled' => false])->assertOk();

    $create('music', 'create_song')->assertUnprocessable()->assertJsonValidationErrors(['module' => 'turned off']);
    actingIn($ada, $workspace)->patchJson("/api/v1/tasks/{$song->id}", ['title' => 'New'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('module');
});

// Code outside app/Modules reaches modules only through the registry (and config/modules.php).
foreach (['App\Core', 'App\Actions', 'App\Enums', 'App\Http', 'App\Models', 'App\Policies', 'App\Providers'] as $namespace) {
    arch("{$namespace} does not import module code")
        ->expect($namespace)
        ->not->toUse('App\Modules');
}
