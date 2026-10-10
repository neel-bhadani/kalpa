<?php

use App\Core\Modules\ModuleRegistry;
use App\Enums\WorkspaceRole;
use App\Models\Asset;
use App\Models\AssetVersion;
use App\Models\File;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Fixtures\CoreKindManifest;
use Tests\Fixtures\DuplicateKindManifest;

// A files row in the workspace (no bytes needed for these tests).
function fileIn(Workspace $workspace, User $user, string $mime = 'image/png'): File
{
    $sha = hash('sha256', Str::random(32));

    return File::create([
        'workspace_id' => $workspace->id, 'sha256' => $sha, 'size_bytes' => 100, 'mime' => $mime,
        'storage_disk' => 's3', 'path' => File::pathFor($workspace->id, $sha), 'created_by' => $user->id,
    ]);
}

function makeCharacter(User $user, Workspace $workspace, array $extra = []): string
{
    return actingIn($user, $workspace)->postJson('/api/v1/assets', [
        'kind' => 'character',
        'name' => 'Mira',
        'spec' => ['name' => 'Mira', 'style' => '2d'],
        ...$extra,
    ])->assertCreated()->json('data.id');
}

it('creates an asset with version 1, a project link and a task output', function () {
    $ada = owner();
    $workspace = workspaceOf($ada);
    $project = makeProject($ada);
    $task = makeTask($project, $ada, 'Design Mira', 'character', 'create_character');
    $image = fileIn($workspace, $ada);

    $id = actingIn($ada, $workspace)->postJson('/api/v1/assets', [
        'kind' => 'character',
        'name' => 'Mira',
        'spec' => ['name' => 'Mira', 'style' => '2d'],
        'tags' => ['hero', 'lantern'],
        'project_id' => $project->id,
        'task_id' => $task->id,
        'files' => [['file_id' => $image->id, 'role' => 'image']],
    ])
        ->assertCreated()
        ->assertJsonPath('data.module', 'character')
        ->assertJsonPath('data.kind_label', 'Character')
        ->assertJsonPath('data.current_version.number', 1)
        ->assertJsonPath('data.tags', ['hero', 'lantern'])
        ->assertJsonPath('data.files.0.role', 'image')
        ->assertJsonPath('data.projects.0.name', 'Film')
        ->json('data.id');

    expect($image->refresh()->ref_count)->toBe(1);
    actingIn($ada, $workspace)->getJson("/api/v1/tasks/{$task->id}/outputs")
        ->assertJsonPath('data.0.role', 'primary')
        ->assertJsonPath('data.0.asset.id', $id);
    actingIn($ada, $workspace)->getJson("/api/v1/projects/{$project->id}/assets")
        ->assertJsonPath('data.0.asset.id', $id)
        ->assertJsonPath('data.0.pinned_version', null);
});

it('rejects a spec that does not match the kind schema', function () {
    $ada = owner();

    actingIn($ada, workspaceOf($ada))->postJson('/api/v1/assets', [
        'kind' => 'character', 'name' => 'Nobody', 'spec' => ['style' => 'watercolour'],
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['spec', 'spec.style']);

    actingIn($ada, workspaceOf($ada))->postJson('/api/v1/assets', ['kind' => 'dragon', 'name' => 'X', 'spec' => new stdClass])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['kind' => 'no "dragon" asset kind']);

    expect(Asset::count())->toBe(0);
});

it('refuses kinds of a turned-off module', function () {
    $ada = owner();
    $workspace = workspaceOf($ada);
    actingIn($ada, $workspace)->patchJson('/api/v1/modules/character', ['enabled' => false])->assertOk();

    actingIn($ada, $workspace)->postJson('/api/v1/assets', ['kind' => 'character', 'name' => 'Mira', 'spec' => ['name' => 'Mira', 'style' => '2d']])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['kind' => 'turned off']);
});

it('numbers versions and returns 409 for a stale base', function () {
    $ada = owner();
    $workspace = workspaceOf($ada);
    $id = makeCharacter($ada, $workspace);
    $patch = fn (int $base, string $description) => actingIn($ada, $workspace)->postJson("/api/v1/assets/{$id}/versions", [
        'base_version' => $base,
        'patch' => [['op' => 'add', 'path' => '/description', 'value' => $description]],
        'message' => 'Describe her',
    ]);

    $patch(1, 'Sells lanterns')->assertCreated()->assertJsonPath('data.number', 2)->assertJsonPath('data.spec.description', 'Sells lanterns');
    $patch(2, 'Night market')->assertCreated()->assertJsonPath('data.number', 3);

    $patch(2, 'Too late')
        ->assertConflict()
        ->assertJsonPath('data.number', 3)
        ->assertJsonPath('data.spec.description', 'Night market');

    actingIn($ada, $workspace)->getJson("/api/v1/assets/{$id}/versions")
        ->assertJsonPath('data.*.number', [3, 2, 1])
        ->assertJsonPath('data.0.author', 'Ada')
        ->assertJsonPath('data.0.message', 'Describe her');
});

it('makes versions from a patch or a full spec, and validates the result', function () {
    $ada = owner();
    $workspace = workspaceOf($ada);
    $id = makeCharacter($ada, $workspace);

    actingIn($ada, $workspace)->postJson("/api/v1/assets/{$id}/versions", [
        'base_version' => 1,
        'patch' => [['op' => 'replace', 'path' => '/style', 'value' => '3d']],
    ])->assertCreated()->assertJsonPath('data.patch.0.op', 'replace');

    actingIn($ada, $workspace)->postJson("/api/v1/assets/{$id}/versions", [
        'base_version' => 2,
        'spec' => ['name' => 'Mira Rao', 'style' => 'realistic'],
        'message' => 'Rewrite',
    ])->assertCreated()->assertJsonPath('data.spec', ['name' => 'Mira Rao', 'style' => 'realistic'])->assertJsonPath('data.patch', null);

    // Removing a required field makes the result invalid.
    actingIn($ada, $workspace)->postJson("/api/v1/assets/{$id}/versions", [
        'base_version' => 3,
        'patch' => [['op' => 'remove', 'path' => '/name']],
    ])->assertUnprocessable()->assertJsonValidationErrors('spec');

    actingIn($ada, $workspace)->postJson("/api/v1/assets/{$id}/versions", ['base_version' => 3])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['patch', 'spec']);

    expect(Asset::find($id)->currentVersion->number)->toBe(3);
});

it('replaces only the given file roles in a new version', function () {
    $ada = owner();
    $workspace = workspaceOf($ada);
    [$image, $thumb, $newImage] = [fileIn($workspace, $ada), fileIn($workspace, $ada), fileIn($workspace, $ada)];
    $id = makeCharacter($ada, $workspace, ['files' => [
        ['file_id' => $image->id, 'role' => 'image'],
        ['file_id' => $thumb->id, 'role' => 'thumbnail'],
    ]]);

    actingIn($ada, $workspace)->postJson("/api/v1/assets/{$id}/versions", [
        'base_version' => 1,
        'patch' => [['op' => 'add', 'path' => '/description', 'value' => 'New look']],
        'files' => [['file_id' => $newImage->id, 'role' => 'image']],
    ])->assertCreated();

    $files = actingIn($ada, $workspace)->getJson("/api/v1/assets/{$id}/versions/2")->json('data.files');
    expect(collect($files)->pluck('file_id', 'role')->all())->toEqual(['thumbnail' => $thumb->id, 'image' => $newImage->id])
        ->and($thumb->refresh()->ref_count)->toBe(2)
        ->and($image->refresh()->ref_count)->toBe(1)
        ->and($newImage->refresh()->ref_count)->toBe(1);
});

it('restores an old version as a new one', function () {
    $ada = owner();
    $workspace = workspaceOf($ada);
    $image = fileIn($workspace, $ada);
    $id = makeCharacter($ada, $workspace, ['files' => [['file_id' => $image->id, 'role' => 'image']]]);

    actingIn($ada, $workspace)->postJson("/api/v1/assets/{$id}/versions", [
        'base_version' => 1,
        'spec' => ['name' => 'Someone else', 'style' => '3d'],
        'files' => [['file_id' => fileIn($workspace, $ada)->id, 'role' => 'image']],
    ])->assertCreated();

    actingIn($ada, $workspace)->postJson("/api/v1/assets/{$id}/restore/1")
        ->assertCreated()
        ->assertJsonPath('data.number', 3)
        ->assertJsonPath('data.message', 'Restored v1')
        ->assertJsonPath('data.spec', ['name' => 'Mira', 'style' => '2d'])
        ->assertJsonPath('data.files.0.file_id', $image->id);

    actingIn($ada, $workspace)->postJson("/api/v1/assets/{$id}/restore/9")->assertNotFound();
});

it('refuses files from another workspace', function () {
    $ada = owner();
    $bob = owner('Bob');
    $bobsFile = fileIn(workspaceOf($bob), $bob);

    actingIn($ada, workspaceOf($ada))->postJson('/api/v1/assets', [
        'kind' => 'image', 'name' => 'Stolen', 'spec' => ['mime' => 'image/png'],
        'files' => [['file_id' => $bobsFile->id, 'role' => 'image']],
    ])->assertUnprocessable()->assertJsonValidationErrors('files');

    expect($bobsFile->refresh()->ref_count)->toBe(0);
});

it('links assets, but not to themselves or twice', function () {
    $ada = owner();
    $workspace = workspaceOf($ada);
    $mira = makeCharacter($ada, $workspace);
    $voice = actingIn($ada, $workspace)->postJson('/api/v1/assets', [
        'kind' => 'voice_profile', 'name' => 'Mira voice', 'spec' => ['name' => 'Mira voice', 'language' => 'en'],
    ])->json('data.id');
    $link = fn (string $from, string $to) => actingIn($ada, $workspace)->postJson("/api/v1/assets/{$from}/links", ['to_asset_id' => $to, 'relation' => 'voice_of']);

    $link($voice, $mira)->assertCreated()->assertJsonPath('data.asset.name', 'Mira');
    $link($voice, $mira)->assertUnprocessable()->assertJsonValidationErrors(['relation' => 'already linked']);
    $link($mira, $mira)->assertUnprocessable()->assertJsonValidationErrors(['to_asset_id' => 'itself']);

    actingIn($ada, $workspace)->getJson("/api/v1/assets/{$mira}/links")
        ->assertJsonPath('data.0.direction', 'in')
        ->assertJsonPath('data.0.asset.name', 'Mira voice');

    actingIn($ada, $workspace)->deleteJson("/api/v1/assets/{$voice}/links", ['to_asset_id' => $mira, 'relation' => 'voice_of'])->assertNoContent();
    actingIn($ada, $workspace)->getJson("/api/v1/assets/{$mira}/links")->assertJsonCount(0, 'data');

    // The database refuses links across workspaces too.
    $bob = owner('Bob');
    $bobs = actingIn($bob, workspaceOf($bob))->postJson('/api/v1/assets', ['kind' => 'file', 'name' => 'x', 'spec' => ['mime' => 'text/plain']])->json('data.id');
    actingIn($ada, $workspace)->postJson("/api/v1/assets/{$mira}/links", ['to_asset_id' => $bobs, 'relation' => 'uses'])->assertNotFound();
    expect(fn () => DB::transaction(fn () => DB::table('asset_links')->insert([
        'id' => Str::uuid7(), 'workspace_id' => $workspace->id, 'from_asset_id' => $mira, 'to_asset_id' => $bobs, 'relation' => 'uses',
    ])))->toThrow(QueryException::class, 'foreign key');
});

it('does not let asset versions be changed or removed', function () {
    $ada = owner();
    $id = makeCharacter($ada, workspaceOf($ada));

    expect(fn () => DB::transaction(fn () => AssetVersion::where('asset_id', $id)->update(['message' => 'rewritten'])))
        ->toThrow(QueryException::class, 'append-only');
    expect(fn () => DB::transaction(fn () => DB::table('asset_versions')->where('asset_id', $id)->delete()))
        ->toThrow(QueryException::class, 'append-only');
});

it('searches by name and tag, and filters', function () {
    $ada = owner();
    $workspace = workspaceOf($ada);
    $project = makeProject($ada);
    makeCharacter($ada, $workspace, ['name' => 'Lantern Seller', 'tags' => ['night-market'], 'project_id' => $project->id]);
    makeCharacter($ada, $workspace, ['name' => 'Tea Vendor', 'tags' => ['brand', 'Ünïcode']]);
    actingIn($ada, $workspace)->postJson('/api/v1/assets', ['kind' => 'image', 'name' => 'Poster', 'spec' => ['mime' => 'image/png']]);
    $names = fn (string $query) => actingIn($ada, $workspace)->getJson("/api/v1/assets{$query}")->assertOk()->json('data.*.name');

    expect($names('?q=lant'))->toBe(['Lantern Seller'])
        ->and($names('?q=TEA vend'))->toBe(['Tea Vendor'])
        ->and($names('?q=brand'))->toBe(['Tea Vendor'])
        ->and($names('?q=ünïcode'))->toBe(['Tea Vendor'])
        ->and($names('?q=night'))->toBe(['Lantern Seller'])
        ->and($names('?tag=night-market'))->toBe(['Lantern Seller'])
        ->and($names('?kind=image'))->toBe(['Poster'])
        ->and($names('?module=core'))->toBe(['Poster'])
        ->and($names("?project={$project->id}"))->toBe(['Lantern Seller'])
        ->and($names('?q=zebra'))->toBe([]);
});

it('lets a viewer read assets but not write', function () {
    $ada = owner();
    $workspace = workspaceOf($ada);
    $project = makeProject($ada);
    $id = makeCharacter($ada, $workspace);
    $viewer = memberOf($workspace, WorkspaceRole::Viewer);

    actingIn($viewer, $workspace)->getJson("/api/v1/assets/{$id}")->assertOk();
    actingIn($viewer, $workspace)->getJson("/api/v1/assets/{$id}/versions")->assertOk();
    actingIn($viewer, $workspace)->postJson('/api/v1/assets', ['kind' => 'file', 'name' => 'x', 'spec' => ['mime' => 'text/plain']])->assertForbidden();
    actingIn($viewer, $workspace)->patchJson("/api/v1/assets/{$id}", ['name' => 'Nope'])->assertForbidden();
    actingIn($viewer, $workspace)->postJson("/api/v1/assets/{$id}/versions", ['base_version' => 1, 'spec' => ['name' => 'x', 'style' => '2d']])->assertForbidden();
    actingIn($viewer, $workspace)->postJson("/api/v1/assets/{$id}/restore/1")->assertForbidden();
    actingIn($viewer, $workspace)->postJson("/api/v1/projects/{$project->id}/assets", ['asset_id' => $id])->assertForbidden();
    actingIn($viewer, $workspace)->deleteJson("/api/v1/assets/{$id}")->assertForbidden();

    $bob = owner('Bob');
    actingIn($bob, workspaceOf($bob))->getJson("/api/v1/assets/{$id}")->assertForbidden();
});

it('attaches an asset to a project and pins a version', function () {
    $ada = owner();
    $workspace = workspaceOf($ada);
    $project = makeProject($ada);
    $id = makeCharacter($ada, $workspace);
    $v1 = Asset::find($id)->current_version_id;

    actingIn($ada, $workspace)->postJson("/api/v1/projects/{$project->id}/assets", ['asset_id' => $id, 'pinned_version_id' => $v1, 'role' => 'lead'])
        ->assertCreated()
        ->assertJsonPath('data.pinned_version.number', 1)
        ->assertJsonPath('data.role', 'lead');

    // Unpin: follow the latest version again.
    actingIn($ada, $workspace)->postJson("/api/v1/projects/{$project->id}/assets", ['asset_id' => $id])
        ->assertOk()
        ->assertJsonPath('data.pinned_version', null);

    actingIn($ada, $workspace)->deleteJson("/api/v1/projects/{$project->id}/assets", ['asset_id' => $id])->assertNoContent();
    actingIn($ada, $workspace)->getJson("/api/v1/projects/{$project->id}/assets")->assertJsonCount(0, 'data');
});

it('lists asset kinds per module', function () {
    $ada = owner();

    actingIn($ada, workspaceOf($ada))->getJson('/api/v1/modules')
        ->assertJsonPath('data.0.asset_kinds', [['key' => 'story', 'label' => 'Story'], ['key' => 'script', 'label' => 'Script']])
        ->assertJsonPath('data.7.asset_kinds', []);
});

it('rejects an asset kind claimed by two owners', function () {
    $manifests = config('modules.manifests');

    expect(fn () => new ModuleRegistry([...$manifests, DuplicateKindManifest::class]))
        ->toThrow(InvalidArgumentException::class, 'Asset kind [script] is claimed by both [story] and [copycat]');
    expect(fn () => new ModuleRegistry([...$manifests, CoreKindManifest::class]))
        ->toThrow(InvalidArgumentException::class, 'Asset kind [image] is claimed by both [core] and [copycat]');
});
