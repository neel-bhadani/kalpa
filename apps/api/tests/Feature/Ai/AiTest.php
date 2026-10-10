<?php

use App\Enums\AiStepStatus;
use App\Enums\WorkspaceRole;
use App\Models\AiHandoff;
use App\Models\AiMessage;
use App\Models\AiProviderConfig;
use App\Models\AiStep;
use App\Models\ChangeLog;
use App\Models\RegisteredModel;
use App\Models\Task;
use App\Models\UserDevice;
use Database\Seeders\ModelRegistrySeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->seed(ModelRegistrySeeder::class);
});

// A task with an open assistant session; returns [user, workspace, task, sessionId].
function aiSession(string $mode = 'semi'): array
{
    $ada = owner();
    $workspace = workspaceOf($ada);
    $task = makeTask(makeProject($ada), $ada);
    $session = actingIn($ada, $workspace)->postJson("/api/v1/tasks/{$task->id}/ai/sessions", ['mode' => $mode])
        ->assertCreated()
        ->json('data.id');

    return [$ada, $workspace, $task, $session];
}

function aiStep($user, $workspace, string $session, string $status = 'awaiting_review'): string
{
    return actingIn($user, $workspace)->postJson("/api/v1/ai/sessions/{$session}/steps", [
        'title' => 'Add scene 1', 'tool' => 'notes.append', 'args' => ['text' => 'Scene 1'], 'status' => $status,
    ])->assertCreated()->json('data.id');
}

it('seeds the model registry from config, all off except the fake brain', function () {
    expect(RegisteredModel::count())->toBe(count(config('models')))
        ->and(RegisteredModel::where('enabled', true)->pluck('key')->all())->toBe(['fake-brain'])
        ->and(RegisteredModel::where('key', 'trellis')->value('commercial_ok'))->toBeFalse()
        ->and(RegisteredModel::where('key', 'ace-step-1.5')->value('commercial_ok'))->toBeTrue()
        ->and(RegisteredModel::where('key', 'kokoro-82m')->first()->targets)->toBe(['device', 'worker']);

    // Running it again changes nothing.
    $this->seed(ModelRegistrySeeder::class);
    expect(RegisteredModel::count())->toBe(count(config('models')));
});

it('refuses to enable a model whose licence does not allow commercial use', function () {
    expect(fn () => DB::transaction(fn () => RegisteredModel::where('key', 'piper')->update(['enabled' => true])))
        ->toThrow(QueryException::class, 'model_registry_enabled_commercial_check');
});

it('marks which models fit this device', function () {
    $ada = owner();
    $device = UserDevice::create(['user_id' => $ada->id, 'client_id' => (string) Str::uuid(), 'platform' => 'web', 'name' => 'Laptop', 'capabilities' => [], 'tier' => 'mid']);

    $models = collect(actingIn($ada, workspaceOf($ada))->withHeader('X-Device-Id', $device->id)->getJson('/api/v1/models')->assertOk()->json('data'))
        ->keyBy('key');

    expect($models['qwen3-1.7b']['fits_device'])->toBeTrue()   // mid on a mid device
        ->and($models['qwen3-4b']['fits_device'])->toBeFalse()   // needs high
        ->and($models['flux1-schnell']['fits_device'])->toBeFalse() // worker only
        ->and($models['trellis']['licence'])->toStartWith('unclear:');
});

it('stores provider keys encrypted and never returns them', function () {
    $ada = owner();
    $key = 'sk-test-1234567890abcdWXYZ';

    $response = $this->actingAs($ada)->postJson('/api/v1/ai/providers', [
        'provider' => 'openai_compatible', 'name' => 'My provider', 'base_url' => 'https://api.example.com/v1', 'api_key' => $key, 'models' => ['some-model'],
    ])->assertCreated()->assertJsonPath('data.api_key_hint', '…WXYZ');

    expect($response->getContent())->not->toContain($key);

    $stored = DB::table('ai_provider_configs')->value('api_key');
    expect($stored)->not->toContain($key)
        ->and(Crypt::decryptString($stored))->toBe($key);

    $id = $response->json('data.id');
    // An empty key on update keeps the old one.
    $this->actingAs($ada)->patchJson("/api/v1/ai/providers/{$id}", ['name' => 'Renamed', 'api_key' => ''])->assertJsonPath('data.api_key_hint', '…WXYZ');
    expect($this->actingAs($ada)->getJson('/api/v1/ai/providers')->getContent())->not->toContain($key);

    // Other people can't see or change it.
    $bob = owner('Bob');
    $this->actingAs($bob)->getJson('/api/v1/ai/providers')->assertJsonCount(0, 'data');
    $this->actingAs($bob)->patchJson("/api/v1/ai/providers/{$id}", ['name' => 'Mine'])->assertNotFound();
    expect(AiProviderConfig::find($id)->name)->toBe('Renamed');
});

it('starts a session with the task module assistant and records messages', function () {
    [$ada, $workspace, $task, $session] = aiSession();

    actingIn($ada, $workspace)->postJson("/api/v1/ai/sessions/{$session}/messages", ['role' => 'user', 'content' => ['text' => 'demo']])->assertCreated();
    actingIn($ada, $workspace)->postJson("/api/v1/ai/sessions/{$session}/messages", ['role' => 'assistant', 'content' => ['text' => 'On it.', 'tool_calls' => []]])->assertCreated();

    actingIn($ada, $workspace)->getJson("/api/v1/ai/sessions/{$session}")
        ->assertJsonPath('data.assistant', 'story')
        ->assertJsonPath('data.mode', 'semi')
        ->assertJsonPath('data.brain_model_key', 'fake-brain')
        ->assertJsonPath('data.messages.*.content.text', ['demo', 'On it.']);

    actingIn($ada, $workspace)->patchJson("/api/v1/ai/sessions/{$session}", ['mode' => 'auto'])->assertJsonPath('data.mode', 'auto');
    actingIn($ada, $workspace)->getJson("/api/v1/tasks/{$task->id}/ai/sessions")->assertJsonCount(1, 'data');
});

it('gives a second device the task\'s active session instead of a new one', function () {
    // Two devices open a new task at once: both ask to start a session.
    [$ada, $workspace, $task, $session] = aiSession();

    actingIn($ada, $workspace)->postJson("/api/v1/tasks/{$task->id}/ai/sessions")
        ->assertOk()
        ->assertJsonPath('data.id', $session);
    actingIn($ada, $workspace)->getJson("/api/v1/tasks/{$task->id}/ai/sessions")->assertJsonCount(1, 'data');
});

it('does not let messages be changed or removed', function () {
    [$ada, $workspace, , $session] = aiSession();
    actingIn($ada, $workspace)->postJson("/api/v1/ai/sessions/{$session}/messages", ['role' => 'user', 'content' => ['text' => 'hi']]);

    expect(fn () => DB::transaction(fn () => AiMessage::query()->update(['tokens' => 5])))->toThrow(QueryException::class, 'append-only');
    expect(fn () => DB::transaction(fn () => AiMessage::query()->delete()))->toThrow(QueryException::class, 'append-only');
});

it('allows only the step moves in the map', function (string $from, string $to, bool $allowed) {
    [$ada, $workspace, , $session] = aiSession();
    $step = aiStep($ada, $workspace, $session);
    AiStep::whereKey($step)->update(['status' => $from]);

    $response = actingIn($ada, $workspace)->patchJson("/api/v1/ai/steps/{$step}", ['status' => $to]);

    $allowed ? $response->assertOk()->assertJsonPath('data.status', $to) : $response->assertUnprocessable()->assertJsonValidationErrors('status');
})->with(function () {
    $map = [
        'planned' => ['awaiting_review', 'running', 'rejected'],
        'awaiting_review' => ['running', 'rejected'],
        'running' => ['done', 'failed'],
        'done' => ['undone'],
        'failed' => ['running'],
        'rejected' => [],
        'undone' => [],
    ];
    foreach (AiStepStatus::cases() as $from) {
        foreach (AiStepStatus::cases() as $to) {
            yield "{$from->value} → {$to->value}" => [$from->value, $to->value, in_array($to->value, $map[$from->value], true)];
        }
    }
});

it('numbers steps and stores what they touched', function () {
    [$ada, $workspace, , $session] = aiSession();
    $first = aiStep($ada, $workspace, $session, 'running');
    $second = aiStep($ada, $workspace, $session, 'planned');

    actingIn($ada, $workspace)->patchJson("/api/v1/ai/steps/{$first}", [
        'status' => 'done', 'before' => ['data' => ['notes' => '']], 'after' => ['data' => ['notes' => 'Scene 1'], 'view' => new stdClass],
    ])->assertOk()->assertJsonPath('data.after.data.notes', 'Scene 1');

    expect(AiStep::find($first)->seq)->toBe(1)->and(AiStep::find($second)->seq)->toBe(2);
    // {} stays {}.
    expect(actingIn($ada, $workspace)->getJson("/api/v1/ai/sessions/{$session}")->getContent())->toContain('"view":{}');

    actingIn($ada, $workspace)->postJson("/api/v1/ai/sessions/{$session}/steps", ['title' => 'x', 'tool' => 'x', 'args' => [], 'status' => 'done'])
        ->assertUnprocessable();
});

it('approves, rejects, undoes and retries steps', function () {
    [$ada, $workspace, , $session] = aiSession();
    $a = aiStep($ada, $workspace, $session);
    $b = aiStep($ada, $workspace, $session);

    actingIn($ada, $workspace)->postJson("/api/v1/ai/steps/{$a}/approve")->assertJsonPath('data.status', 'running');
    actingIn($ada, $workspace)->patchJson("/api/v1/ai/steps/{$a}", ['status' => 'done'])->assertOk();
    actingIn($ada, $workspace)->postJson("/api/v1/ai/steps/{$a}/undo")->assertJsonPath('data.status', 'undone');
    actingIn($ada, $workspace)->postJson("/api/v1/ai/steps/{$a}/undo")->assertUnprocessable();

    actingIn($ada, $workspace)->postJson("/api/v1/ai/steps/{$b}/reject")->assertJsonPath('data.status', 'rejected');
    actingIn($ada, $workspace)->postJson("/api/v1/ai/steps/{$b}/approve")->assertUnprocessable();

    $c = aiStep($ada, $workspace, $session, 'running');
    actingIn($ada, $workspace)->patchJson("/api/v1/ai/steps/{$c}", ['status' => 'failed', 'error' => 'Bad args: text is required.'])->assertJsonPath('data.error', 'Bad args: text is required.');
    actingIn($ada, $workspace)->postJson("/api/v1/ai/steps/{$c}/retry")->assertJsonPath('data.status', 'running')->assertJsonPath('data.error', null);
});

it('writes change_log rows for sessions and steps', function () {
    [$ada, $workspace, , $session] = aiSession();
    $after = ChangeLog::max('seq');

    $step = aiStep($ada, $workspace, $session);
    actingIn($ada, $workspace)->postJson("/api/v1/ai/steps/{$step}/approve");
    actingIn($ada, $workspace)->patchJson("/api/v1/ai/sessions/{$session}", ['mode' => 'auto']);

    expect(ChangeLog::where('seq', '>', $after)->orderBy('seq')->get()->map(fn ($row) => [$row->entity->value, $row->entity_id])->all())
        ->toBe([['ai_step', $step], ['ai_step', $step], ['ai_session', $session]]);

    // And other devices pull them.
    actingIn($ada, $workspace)->getJson("/api/v1/sync?after={$after}")
        ->assertJsonPath('data.0.entity', 'ai_step')
        ->assertJsonPath('data.0.payload.status', 'running');
});

it('lets viewers read but not act, and keeps other workspaces out', function () {
    [$ada, $workspace, $task, $session] = aiSession();
    $step = aiStep($ada, $workspace, $session);
    $viewer = memberOf($workspace, WorkspaceRole::Viewer);

    actingIn($viewer, $workspace)->getJson("/api/v1/ai/sessions/{$session}")->assertOk();
    actingIn($viewer, $workspace)->postJson("/api/v1/tasks/{$task->id}/ai/sessions")->assertForbidden();
    actingIn($viewer, $workspace)->postJson("/api/v1/ai/sessions/{$session}/messages", ['role' => 'user', 'content' => ['text' => 'x']])->assertForbidden();
    actingIn($viewer, $workspace)->postJson("/api/v1/ai/steps/{$step}/approve")->assertForbidden();
    actingIn($viewer, $workspace)->postJson("/api/v1/projects/{$task->project_id}/plans", ['goal' => 'x'])->assertForbidden();

    $bob = owner('Bob');
    actingIn($bob, workspaceOf($bob))->getJson("/api/v1/ai/sessions/{$session}")->assertForbidden();
    actingIn($bob, workspaceOf($bob))->postJson("/api/v1/ai/steps/{$step}/reject")->assertForbidden();
    actingIn($bob, $workspace)->getJson("/api/v1/ai/sessions/{$session}")->assertForbidden();
});

it('lets the Producer plan three tasks with dependencies and handoffs', function () {
    $ada = owner();
    $workspace = workspaceOf($ada);
    $project = makeProject($ada);

    $plan = actingIn($ada, $workspace)->postJson("/api/v1/projects/{$project->id}/plans", ['goal' => 'A night market short'])
        ->assertCreated()
        ->assertJsonPath('data.session.assistant', 'producer')
        ->assertJsonPath('data.tasks.*.module', ['story', 'character', 'voice'])
        ->assertJsonPath('data.handoffs.*.request.assistant', ['story', 'character', 'voice'])
        ->json('data');

    [$script, $character, $dialogue] = array_map(fn ($task) => Task::find($task['id']), $plan['tasks']);
    expect($script->dependsOn()->pluck('tasks.id')->all())->toBe([])
        ->and($character->dependsOn()->pluck('tasks.id')->all())->toBe([$script->id])
        ->and($dialogue->dependsOn()->pluck('tasks.id')->all())->toBe([$character->id])
        ->and($character->kind)->toBe('create_character')
        ->and(AiHandoff::where('from_session_id', $plan['session']['id'])->pluck('status')->map->value->unique()->all())->toBe(['pending'])
        ->and(AiMessage::where('session_id', $plan['session']['id'])->count())->toBe(2);
});
