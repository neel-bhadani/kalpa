<?php

use App\Core\Storage\ObjectStorage;
use App\Enums\UploadStatus;
use App\Enums\WorkspaceRole;
use App\Models\File;
use App\Models\Upload;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

// These tests talk to the real kalpa-test bucket, with the smallest parts S3 allows (PART, in Pest.php).
beforeEach(function () {
    config(['uploads.part_size' => PART]);
});

it('uploads in parts and records the file', function () {
    $ada = owner();
    $workspace = workspaceOf($ada);
    $bytes = random_bytes(PART + 476); // two parts: 5 MB + 476 bytes

    $upload = actingIn($ada, $workspace)->postJson('/api/v1/uploads', startUpload($bytes))
        ->assertCreated()
        ->assertJsonPath('data.deduplicated', false)
        ->assertJsonPath('data.upload.part_size', PART)
        ->assertJsonPath('data.upload.part_count', 2)
        ->json('data.upload');

    $parts = putParts($upload['id'], $bytes);

    // A reloaded page sees both parts as done.
    actingIn($ada, $workspace)->getJson("/api/v1/uploads/{$upload['id']}")
        ->assertOk()
        ->assertJsonPath('data.parts.*.number', [1, 2])
        ->assertJsonPath('data.parts.1.size', 476);

    $fileId = actingIn($ada, $workspace)->postJson("/api/v1/uploads/{$upload['id']}/complete", ['parts' => $parts])
        ->assertOk()
        ->assertJsonPath('data.file.size_bytes', PART + 476)
        ->assertJsonPath('data.file.mime', 'video/mp4')
        ->assertJsonPath('data.file.verified_at', null)
        ->json('data.file.id');

    $file = File::find($fileId);
    $sha = hash('sha256', $bytes);
    expect($file->path)->toBe("ws/{$workspace->id}/".substr($sha, 0, 2).'/'.substr($sha, 2, 2)."/{$sha}")
        ->and(app(ObjectStorage::class)->size($file->path))->toBe(PART + 476)
        ->and(Upload::find($upload['id'])->status)->toBe(UploadStatus::Completed);

    // The signed download URL returns the same bytes.
    $url = actingIn($ada, $workspace)->getJson("/api/v1/files/{$fileId}/url")->assertOk()->json('data.url');
    expect(Http::get($url)->body() === $bytes)->toBeTrue();
});

it('returns the existing file for content the workspace already has', function () {
    $ada = owner();
    $workspace = workspaceOf($ada);
    $bytes = Str::random(700);
    $file = File::create([
        'workspace_id' => $workspace->id, 'sha256' => hash('sha256', $bytes), 'size_bytes' => 700, 'mime' => 'video/mp4',
        'storage_disk' => 's3', 'path' => File::pathFor($workspace->id, hash('sha256', $bytes)), 'created_by' => $ada->id,
    ]);

    actingIn($ada, $workspace)->postJson('/api/v1/uploads', startUpload($bytes))
        ->assertOk()
        ->assertJsonPath('data.deduplicated', true)
        ->assertJsonPath('data.file.id', $file->id);

    expect(Upload::count())->toBe(0);

    // Another workspace with the same bytes gets its own upload: dedupe never crosses workspaces.
    $bob = owner('Bob');
    actingIn($bob, workspaceOf($bob))->postJson('/api/v1/uploads', startUpload($bytes))
        ->assertCreated()
        ->assertJsonPath('data.deduplicated', false);
});

it('refuses to complete when the parts do not add up to the announced size', function () {
    $ada = owner();
    $workspace = workspaceOf($ada);
    $bytes = random_bytes(PART + 476);
    $claimed = [...startUpload($bytes), 'size_bytes' => PART + 576]; // still two parts, but 100 bytes short

    $uploadId = actingIn($ada, $workspace)->postJson('/api/v1/uploads', $claimed)->assertCreated()->json('data.upload.id');
    $parts = putParts($uploadId, $bytes);

    actingIn($ada, $workspace)->postJson("/api/v1/uploads/{$uploadId}/complete", ['parts' => $parts])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('size_bytes');

    expect(File::count())->toBe(0)
        ->and(Upload::find($uploadId)->status)->toBe(UploadStatus::Pending);

    actingIn($ada, $workspace)->deleteJson("/api/v1/uploads/{$uploadId}")->assertNoContent();
});

it('keeps other workspaces out of an upload and its file', function () {
    $ada = owner();
    $workspace = workspaceOf($ada);
    $bytes = Str::random(900);
    $uploadId = actingIn($ada, $workspace)->postJson('/api/v1/uploads', startUpload($bytes))->json('data.upload.id');
    $fileId = actingIn($ada, $workspace)->postJson("/api/v1/uploads/{$uploadId}/complete", ['parts' => putParts($uploadId, $bytes)])
        ->assertOk()
        ->json('data.file.id');

    $other = owner('Bob');
    $otherSpace = workspaceOf($other);
    $second = actingIn($ada, $workspace)->postJson('/api/v1/uploads', startUpload(Str::random(900)))->json('data.upload.id');

    actingIn($other, $otherSpace)->postJson("/api/v1/uploads/{$second}/parts", ['part_numbers' => [1]])->assertForbidden();
    actingIn($other, $otherSpace)->postJson("/api/v1/uploads/{$second}/complete", ['parts' => [['number' => 1, 'etag' => 'x']]])->assertForbidden();
    actingIn($other, $otherSpace)->getJson("/api/v1/uploads/{$second}")->assertForbidden();
    actingIn($other, $otherSpace)->getJson("/api/v1/files/{$fileId}/url")->assertForbidden();
    actingIn($other, $workspace)->getJson("/api/v1/files/{$fileId}/url")->assertForbidden(); // not a member

    // A viewer in the same workspace can read the file but not upload.
    $viewer = memberOf($workspace, WorkspaceRole::Viewer);
    actingIn($viewer, $workspace)->getJson("/api/v1/files/{$fileId}/url")->assertOk();
    actingIn($viewer, $workspace)->postJson('/api/v1/uploads', startUpload(Str::random(10)))->assertForbidden();
});

it('aborts stale uploads', function () {
    $ada = owner();
    $workspace = workspaceOf($ada);
    $bytes = random_bytes(PART + 476);
    $uploadId = actingIn($ada, $workspace)->postJson('/api/v1/uploads', startUpload($bytes))->json('data.upload.id');
    putParts($uploadId, $bytes);

    $this->travel(25)->hours();
    $this->artisan('uploads:expire')->expectsOutput('Expired 1 upload(s).')->assertSuccessful();

    $upload = Upload::find($uploadId);
    expect($upload->status)->toBe(UploadStatus::Expired)
        ->and(app(ObjectStorage::class)->multipartExists($upload->path, $upload->s3_upload_id))->toBeFalse();

    actingIn($ada, $workspace)->postJson("/api/v1/uploads/{$uploadId}/parts", ['part_numbers' => [1]])
        ->assertUnprocessable();
});

it('rejects a disallowed type or an oversized file', function () {
    $ada = owner();
    $workspace = workspaceOf($ada);
    $start = fn (array $overrides) => actingIn($ada, $workspace)->postJson('/api/v1/uploads', [...startUpload('abc'), ...$overrides]);

    $start(['file_name' => 'page.html', 'mime' => 'text/html'])->assertUnprocessable()->assertJsonValidationErrors('file_name');
    $start(['file_name' => 'logo.svg', 'mime' => 'image/svg+xml'])->assertUnprocessable()->assertJsonValidationErrors('file_name');
    $start(['file_name' => 'photo.png', 'mime' => 'text/html'])->assertUnprocessable()->assertJsonValidationErrors('file_name');
    $start(['file_name' => 'movie.mp4', 'size_bytes' => 2 * 1024 ** 3 + 1])->assertUnprocessable()->assertJsonValidationErrors('size_bytes');
    $start(['sha256' => 'not-a-hash'])->assertUnprocessable()->assertJsonValidationErrors('sha256');

    // 3D files often arrive without a browser type; the extension decides.
    $start(['file_name' => 'hero.fbx', 'mime' => ''])->assertCreated()->assertJsonPath('data.upload.mime', 'application/octet-stream');
    $start(['file_name' => 'walk.bvh', 'mime' => null, 'sha256' => hash('sha256', 'bvh')])->assertCreated()->assertJsonPath('data.upload.mime', 'text/plain');
});

it("only dedupes to a file that is verified or the user's own", function () {
    // Hold the file.verify_hash jobs, so Ada's file stays unverified for this test.
    Queue::fake();
    $ada = owner();
    $workspace = workspaceOf($ada);
    $bob = memberOf($workspace, WorkspaceRole::Editor);
    $bytes = Str::random(900);

    $uploadId = actingIn($ada, $workspace)->postJson('/api/v1/uploads', startUpload($bytes))->json('data.upload.id');
    $fileId = actingIn($ada, $workspace)->postJson("/api/v1/uploads/{$uploadId}/complete", ['parts' => putParts($uploadId, $bytes)])
        ->json('data.file.id');

    // Ada uploaded it herself, so she gets it back even though it isn't verified yet.
    actingIn($ada, $workspace)->postJson('/api/v1/uploads', startUpload($bytes))->assertJsonPath('data.file.id', $fileId);

    // Bob only knows the hash: he has to upload the bytes, to a temporary path, and gets no file yet.
    $bobUpload = actingIn($bob, $workspace)->postJson('/api/v1/uploads', startUpload($bytes))
        ->assertCreated()
        ->assertJsonPath('data.deduplicated', false)
        ->json('data.upload.id');
    expect(Upload::find($bobUpload)->path)->toBe('tmp/'.$bobUpload.'/'.hash('sha256', $bytes));

    actingIn($bob, $workspace)->postJson("/api/v1/uploads/{$bobUpload}/complete", ['parts' => putParts($bobUpload, $bytes)])
        ->assertOk()
        ->assertJsonPath('data.file', null)
        ->assertJsonPath('data.pending_verification', true);
    expect(File::count())->toBe(1)
        ->and(Upload::find($bobUpload)->status)->toBe(UploadStatus::Completed);

    // Once verified, everyone in the workspace dedupes to it.
    File::find($fileId)->forceFill(['verified_at' => now()])->save();
    actingIn($bob, $workspace)->postJson('/api/v1/uploads', startUpload($bytes))
        ->assertOk()
        ->assertJsonPath('data.deduplicated', true)
        ->assertJsonPath('data.file.id', $fileId);
});
