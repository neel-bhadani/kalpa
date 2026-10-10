<?php

use App\Core\Jobs\JobTypeRegistry;
use App\Models\RegisteredModel;
use Database\Seeders\ModelRegistrySeeder;

// Pretend this is a production server for the rest of the test.
function asProduction(): void
{
    app()->detectEnvironment(fn () => 'production');
    app()->forgetInstance(JobTypeRegistry::class);
}

it('offers the dev tools in development and tests', function () {
    $this->seed(ModelRegistrySeeder::class);
    $ada = owner();

    $this->actingAs($ada)->getJson('/api/v1/me')->assertJsonPath('data.features.dev_tools', true);
    expect(app(JobTypeRegistry::class)->find('demo.count'))->not->toBeNull()
        ->and(RegisteredModel::where('key', 'fake-brain')->exists())->toBeTrue();
});

it('turns every dev tool off outside development', function () {
    $this->seed(ModelRegistrySeeder::class); // as a dev machine would have it
    $ada = owner();
    $workspace = workspaceOf($ada);
    $task = makeTask(makeProject($ada), $ada);

    asProduction();
    app(ModelRegistrySeeder::class)->run();

    // The web app hides the demo buttons and the fake brain.
    $this->actingAs($ada)->getJson('/api/v1/me')->assertJsonPath('data.features.dev_tools', false);
    // The fake brain is gone from the registry, and can't be used.
    expect(RegisteredModel::where('key', 'fake-brain')->exists())->toBeFalse();
    actingIn($ada, $workspace)->postJson("/api/v1/tasks/{$task->id}/ai/sessions")
        ->assertUnprocessable()->assertJsonValidationErrors('brain_model_key');
    // demo.count doesn't exist.
    actingIn($ada, $workspace)->postJson('/api/v1/jobs', ['type' => 'demo.count', 'target' => 'device'])
        ->assertUnprocessable()->assertJsonValidationErrors('type');
    // The Producer demo plan doesn't exist.
    actingIn($ada, $workspace)->postJson("/api/v1/projects/{$task->project_id}/plans", ['goal' => 'x'])->assertNotFound();
});
