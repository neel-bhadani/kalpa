<?php

use App\Enums\WorkspaceRole;
use App\Models\Asset;
use App\Modules\Story\Models\ScriptLine;
use App\Modules\Story\Models\ScriptScene;
use Illuminate\Support\Str;

// A write_script task; returns [user, workspace, task, script asset, story asset].
function scriptTask(string $title = 'Night market'): array
{
    $ada = owner();
    $workspace = workspaceOf($ada);
    $task = makeTask(makeProject($ada), $ada, $title, 'story', 'write_script');
    $outputs = collect(actingIn($ada, $workspace)->getJson("/api/v1/tasks/{$task->id}/outputs")->assertOk()->json('data'));

    return [$ada, $workspace, $task, Asset::find($outputs->firstWhere('role', 'primary')['asset']['id']), Asset::find($outputs->firstWhere('role', 'secondary')['asset']['id'])];
}

function line(string $type, string $text, ?string $id = null): array
{
    return ['id' => $id ?? (string) Str::uuid7(), 'type' => $type, 'text' => $text];
}

function scene(string $heading, array $lines, ?string $id = null): array
{
    return ['id' => $id ?? (string) Str::uuid7(), 'heading' => $heading, 'lines' => $lines];
}

function saveScript($user, $workspace, Asset $script, array $scenes, ?string $message = null)
{
    $current = $script->refresh()->currentVersion;
    $spec = [...(array) json_decode(json_encode($current->spec), true), 'scenes' => $scenes];

    return actingIn($user, $workspace)->postJson("/api/v1/assets/{$script->id}/versions", ['base_version' => $current->number, 'spec' => $spec, 'message' => $message]);
}

// [scene heading => [line texts]] from the projection, in order.
function projected(Asset $script): array
{
    return ScriptScene::where('asset_id', $script->id)->orderBy('position')->with('lines')->get()
        ->mapWithKeys(fn (ScriptScene $scene) => [$scene->heading => $scene->lines->pluck('text')->all()])
        ->all();
}

it('makes a linked script and story for a write_script task, and a story for write_story', function () {
    [$ada, $workspace, $task, $script, $story] = scriptTask();

    expect($script->kind)->toBe('script')
        ->and($story->kind)->toBe('story')
        ->and($script->currentVersion->number)->toBe(1)
        ->and($script->currentVersion->spec->story_asset_id)->toBe($story->id)
        ->and($script->currentVersion->spec->scenes)->toHaveCount(1)
        ->and(ScriptScene::where('asset_id', $script->id)->count())->toBe(1);

    // A second script in the same project uses the same story.
    $second = makeTask($task->project, $ada, 'Second draft', 'story', 'write_script');
    expect(Asset::where('kind', 'story')->count())->toBe(1)
        ->and($second->outputs()->where('asset_id', $story->id)->value('role')->value)->toBe('secondary');

    $storyTask = makeTask($task->project, $ada, 'The story', 'story', 'write_story');
    expect($storyTask->outputs()->with('asset')->get()->map(fn ($output) => [$output->role->value, $output->asset->kind])->all())->toBe([['primary', 'story']]);
});

it('projects scenes and lines: inserts, updates, moves and deletes rows to match the spec', function () {
    [$ada, $workspace, , $script] = scriptTask();
    $market = scene('EXT. NIGHT MARKET - NIGHT', [line('action', 'Lanterns flicker on.'), line('character', 'MIRA'), line('dialogue', 'Late again.')]);
    $alley = scene('EXT. ALLEY - NIGHT', [line('action', 'A cat waits.')]);
    saveScript($ada, $workspace, $script, [$market, $alley])->assertCreated();

    expect(projected($script))->toBe([
        'EXT. NIGHT MARKET - NIGHT' => ['Lanterns flicker on.', 'MIRA', 'Late again.'],
        'EXT. ALLEY - NIGHT' => ['A cat waits.'],
    ]);
    $dialogue = ScriptLine::where('spec_id', $market['lines'][2]['id'])->first();
    expect($dialogue->type->value)->toBe('dialogue');

    // Edit a line, move the dialogue into a new scene, drop the alley.
    $market['lines'][0]['text'] = 'Lanterns flicker on, one by one.';
    $moved = array_pop($market['lines']);
    $roof = scene('EXT. ROOF - NIGHT', [$moved, line('transition', 'CUT TO:')]);
    saveScript($ada, $workspace, $script, [$roof, $market])->assertCreated();

    expect(projected($script))->toBe([
        'EXT. ROOF - NIGHT' => ['Late again.', 'CUT TO:'],
        'EXT. NIGHT MARKET - NIGHT' => ['Lanterns flicker on, one by one.', 'MIRA'],
    ]);
    // Same spec id, same row: other tables can keep pointing at it.
    $again = ScriptLine::where('spec_id', $moved['id'])->first();
    expect($again->id)->toBe($dialogue->id)
        ->and($again->scene->spec_id)->toBe($roof['id'])
        ->and($again->asset_version_id)->toBe($script->refresh()->current_version_id)
        ->and(ScriptLine::where('asset_id', $script->id)->count())->toBe(4)
        ->and(ScriptScene::where('spec_id', $alley['id'])->exists())->toBeFalse();
});

it('commits a version with a message, and restore re-projects the old one', function () {
    [$ada, $workspace, , $script] = scriptTask();
    $first = scene('INT. KITCHEN - DAY', [line('action', 'Steam rises.')]);
    saveScript($ada, $workspace, $script, [$first], 'Edited 1 line in Scene 1')->assertCreated()->assertJsonPath("data.number", 2);
    saveScript($ada, $workspace, $script, [scene('INT. STUDIO - NIGHT', [])], 'Rewrote Scene 1')->assertCreated();

    actingIn($ada, $workspace)->getJson("/api/v1/assets/{$script->id}/versions")
        ->assertJsonPath('data.*.message', ['Rewrote Scene 1', 'Edited 1 line in Scene 1', 'Created']);
    expect(projected($script))->toBe(['INT. STUDIO - NIGHT' => []]);

    actingIn($ada, $workspace)->postJson("/api/v1/assets/{$script->id}/restore/2")->assertCreated()->assertJsonPath('data.number', 4);
    expect(projected($script))->toBe(['INT. KITCHEN - DAY' => ['Steam rises.']])
        ->and(ScriptScene::where('asset_id', $script->id)->value('spec_id'))->toBe($first['id']);
});

it('keeps scripts and stories inside their limits', function (string $kind, Closure $change, string $error) {
    [$ada, $workspace, , $script, $story] = scriptTask();
    $asset = $kind === 'script' ? $script : $story;
    $current = $asset->currentVersion;
    $spec = $change(json_decode(json_encode($current->spec), true));

    actingIn($ada, $workspace)->postJson("/api/v1/assets/{$asset->id}/versions", ['base_version' => $current->number, 'spec' => $spec])
        ->assertUnprocessable()
        ->assertJsonValidationErrors($error);
    expect($asset->refresh()->currentVersion->number)->toBe($current->number);
})->with([
    'unknown line type' => ['script', fn ($spec) => [...$spec, 'scenes' => [scene('INT. A', [line('song', 'La la')])]], 'spec.scenes.0.lines.0.type'],
    'unknown line field' => ['script', fn ($spec) => [...$spec, 'scenes' => [scene('INT. A', [[...line('action', 'x'), 'colour' => 'red']])]], 'spec.scenes.0.lines.0'],
    'line id not a uuid' => ['script', fn ($spec) => [...$spec, 'scenes' => [scene('INT. A', [line('action', 'x', 'line-1')])]], 'spec.scenes.0.lines.0.id'],
    'line text too long' => ['script', fn ($spec) => [...$spec, 'scenes' => [scene('INT. A', [line('action', str_repeat('a', 5001))])]], 'spec.scenes.0.lines.0.text'],
    'over 500 scenes' => ['script', fn ($spec) => [...$spec, 'scenes' => array_map(fn () => scene('INT. A', []), range(1, 501))], 'spec.scenes'],
    'unknown format' => ['script', fn ($spec) => [...$spec, 'format' => 'opera'], 'spec.format'],
    'same line id twice' => ['script', fn ($spec) => [...$spec, 'scenes' => [scene('INT. A', [line('action', 'x', $id = (string) Str::uuid7()), line('action', 'y', $id)])]], 'spec.scenes'],
    'story title too long' => ['story', fn ($spec) => [...$spec, 'title' => str_repeat('t', 201)], 'spec.title'],
    'story over 200 characters' => ['story', fn ($spec) => [...$spec, 'characters' => array_map(fn ($i) => ['id' => (string) Str::uuid7(), 'name' => "C{$i}"], range(1, 201))], 'spec.characters'],
    'story target length' => ['story', fn ($spec) => [...$spec, 'target_length' => 601], 'spec.target_length'],
]);

it('writes Hindi and Gujarati text as it is', function () {
    [$ada, $workspace, , $script] = scriptTask();
    saveScript($ada, $workspace, $script, [scene('INT. घर - रात', [line('dialogue', 'કેમ છો? मैं ठीक हूँ।')])])->assertCreated();

    expect(projected($script))->toBe(['INT. घर - रात' => ['કેમ છો? मैं ठीक हूँ।']]);
});

it('lets viewers read scripts but not edit or restore them', function () {
    [$ada, $workspace, , $script] = scriptTask();
    $viewer = memberOf($workspace, WorkspaceRole::Viewer);

    actingIn($viewer, $workspace)->getJson("/api/v1/assets/{$script->id}/versions")->assertOk();
    saveScript($viewer, $workspace, $script, [scene('INT. A', [])])->assertForbidden();
    actingIn($viewer, $workspace)->postJson("/api/v1/assets/{$script->id}/restore/1")->assertForbidden();
    expect($script->refresh()->currentVersion->number)->toBe(1);
});
