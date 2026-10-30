<?php

use App\Enums\ActorType;
use App\Models\Asset;
use App\Models\AssetVersion;
use App\Models\ProjectMemoryVersion;
use Database\Seeders\ModelRegistrySeeder;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->seed(ModelRegistrySeeder::class);
});

// A script task with an assistant session; returns [user, workspace, task, session id, script asset].
function writingSession(): array
{
    $ada = owner();
    $workspace = workspaceOf($ada);
    $task = makeTask(makeProject($ada), $ada, 'Radio repair', 'story', 'write_script');
    $session = actingIn($ada, $workspace)->postJson("/api/v1/tasks/{$task->id}/ai/sessions", ['mode' => 'auto'])->assertCreated()->json('data.id');
    $script = Asset::find($task->outputs()->where('role', 'primary')->value('asset_id'));

    return [$ada, $workspace, $task, $session, $script];
}

function runningStep($user, $workspace, string $session, string $title = 'Drafted Scene 1', ?string $reason = null): string
{
    return actingIn($user, $workspace)->postJson("/api/v1/ai/sessions/{$session}/steps", [
        'title' => $title, 'tool' => 'script.draftScene', 'args' => ['scene_id' => 'x'], 'status' => 'running', 'reason' => $reason,
    ])->assertCreated()->json('data.id');
}

it('records the reason a step gives', function () {
    [$ada, $workspace, , $session] = writingSession();
    runningStep($ada, $workspace, $session, 'Add an act', 'Adding a midpoint twist so act 2 doesn\'t sag');

    actingIn($ada, $workspace)->getJson("/api/v1/ai/sessions/{$session}")
        ->assertJsonPath('data.steps.0.reason', 'Adding a midpoint twist so act 2 doesn\'t sag');
});

it('makes each AI step its own asset version, carrying the step id', function () {
    [$ada, $workspace, , $session, $script] = writingSession();
    $step = runningStep($ada, $workspace, $session);

    $version = actingIn($ada, $workspace)->postJson("/api/v1/assets/{$script->id}/versions", [
        'base_version' => 1,
        'patch' => [['op' => 'add', 'path' => '/scenes/0/heading', 'value' => 'INT. RADIO SHOP - DAY']],
        'message' => 'AI: Drafted Scene 1',
        'ai_step_id' => $step,
    ])->assertCreated()->assertJsonPath('data.ai_step_id', $step)->json('data.number');

    $row = AssetVersion::where('asset_id', $script->id)->where('number', $version)->first();
    expect($row->created_by_type)->toBe(ActorType::Assistant)
        ->and($row->created_by_id)->toBe($ada->id)
        ->and($row->message)->toBe('AI: Drafted Scene 1');
    actingIn($ada, $workspace)->getJson("/api/v1/ai/sessions/{$session}")->assertJsonPath('data.steps.0.asset_id', $script->id);

    // A person's version has no step.
    actingIn($ada, $workspace)->postJson("/api/v1/assets/{$script->id}/versions", ['base_version' => $version, 'patch' => [['op' => 'add', 'path' => '/title', 'value' => 'Radio']]])
        ->assertCreated()->assertJsonPath('data.ai_step_id', null);
});

it('refuses a step id that is not running or not in this workspace', function () {
    [$ada, $workspace, , $session, $script] = writingSession();
    $step = runningStep($ada, $workspace, $session);
    actingIn($ada, $workspace)->patchJson("/api/v1/ai/steps/{$step}", ['status' => 'done'])->assertOk();
    $patch = [['op' => 'add', 'path' => '/title', 'value' => 'X']];

    actingIn($ada, $workspace)->postJson("/api/v1/assets/{$script->id}/versions", ['base_version' => 1, 'patch' => $patch, 'ai_step_id' => $step])
        ->assertJsonValidationErrors('ai_step_id');
    actingIn($ada, $workspace)->postJson("/api/v1/assets/{$script->id}/versions", ['base_version' => 1, 'patch' => $patch, 'ai_step_id' => (string) Str::uuid7()])
        ->assertJsonValidationErrors('ai_step_id');

    [$bob, $bobsWorkspace, , $bobsSession] = writingSession();
    $bobsStep = runningStep($bob, $bobsWorkspace, $bobsSession);
    actingIn($ada, $workspace)->postJson("/api/v1/assets/{$script->id}/versions", ['base_version' => 1, 'patch' => $patch, 'ai_step_id' => $bobsStep])
        ->assertJsonValidationErrors('ai_step_id');
    expect($script->refresh()->currentVersion->number)->toBe(1);
});

it('records the assistant\'s memory.update as a project memory version', function () {
    [$ada, $workspace, $task, $session] = writingSession();
    $step = runningStep($ada, $workspace, $session, 'Updated the story bible');
    $project = $task->project_id;

    $base = actingIn($ada, $workspace)->getJson("/api/v1/projects/{$project}/memory")->json('data.row_version');
    actingIn($ada, $workspace)->patchJson("/api/v1/projects/{$project}/memory", [
        'base_version' => $base,
        'patch' => [['op' => 'add', 'path' => '/story_bible', 'value' => ['scenes' => ['s1' => 'Asha finds a dead radio in the market.']]]],
        'ai_step_id' => $step,
    ])->assertOk()->assertJsonPath('data.data.story_bible.scenes.s1', 'Asha finds a dead radio in the market.');

    $version = ProjectMemoryVersion::latest('number')->first();
    expect($version->created_by_type)->toBe(ActorType::Assistant)
        ->and($version->ai_step_id)->toBe($step)
        ->and($version->number)->toBe($base + 1);
    actingIn($ada, $workspace)->getJson("/api/v1/projects/{$project}/memory/versions")->assertJsonPath('data.0.ai_step_id', $step);
});

it('keeps a plan, waits for the user, and resumes', function () {
    [$ada, $workspace, $task, $session] = writingSession();
    $plan = ['brief' => 'A girl in Surat fixes old radios', 'stage' => 'characters', 'question' => 'Should Asha have a sister?'];

    actingIn($ada, $workspace)->patchJson("/api/v1/ai/sessions/{$session}", ['status' => 'waiting_user', 'plan' => $plan])
        ->assertOk()
        ->assertJsonPath('data.status', 'waiting_user')
        ->assertJsonPath('data.plan.question', 'Should Asha have a sister?');

    // Opening the task again (another device) finds the waiting session, not a new one.
    actingIn($ada, $workspace)->postJson("/api/v1/tasks/{$task->id}/ai/sessions")->assertOk()->assertJsonPath('data.id', $session);

    actingIn($ada, $workspace)->patchJson("/api/v1/ai/sessions/{$session}", ['status' => 'active', 'plan' => [...$plan, 'question' => null, 'answers' => ['Yes, a younger one']]])
        ->assertJsonPath('data.status', 'active')
        ->assertJsonPath('data.plan.answers', ['Yes, a younger one']);

    actingIn($ada, $workspace)->patchJson("/api/v1/ai/sessions/{$session}", ['plan' => ['big' => str_repeat('x', 70000)]])->assertJsonValidationErrors('plan');
});
