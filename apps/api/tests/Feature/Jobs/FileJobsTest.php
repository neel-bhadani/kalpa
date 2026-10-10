<?php

use App\Core\Jobs\Handlers\MakeThumbnail;
use App\Core\Storage\ObjectStorage;
use App\Enums\JobStatus;
use App\Enums\UploadStatus;
use App\Enums\WorkspaceRole;
use App\Models\File;
use App\Models\FileDerivative;
use App\Models\Job;
use App\Models\Upload;
use App\Notifications\UploadRejected;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

// Real kalpa-test bucket; the test queue is synchronous, so the jobs run during the request.
beforeEach(function () {
    config(['uploads.part_size' => PART]);
});

// A PNG drawn with GD.
function pngBytes(int $width, int $height): string
{
    $image = imagecreatetruecolor($width, $height);
    imagefill($image, 0, 0, imagecolorallocate($image, 139, 124, 255));
    imagefilledellipse($image, (int) ($width * 0.7), (int) ($height * 0.3), (int) ($height * 0.3), (int) ($height * 0.3), imagecolorallocate($image, 236, 238, 245));
    ob_start();
    imagepng($image);

    return (string) ob_get_clean();
}

it('verifies the hash after an upload completes', function () {
    $ada = owner();
    $workspace = workspaceOf($ada);
    $bytes = Str::random(2000);

    $result = uploadBytes($ada, $workspace, $bytes);

    $job = Job::where('type', 'file.verify_hash')->sole();
    expect($job->status)->toBe(JobStatus::Succeeded)
        ->and($job->output->match)->toBeTrue()
        ->and($job->idempotency_key)->toBe("file.verify_hash:{$result['upload_id']}")
        ->and(File::find($result['file']['id'])->verified_at)->not->toBeNull()
        // Not an image: no thumbnail job.
        ->and(Job::where('type', 'file.thumbnail')->exists())->toBeFalse();
});

it('removes an upload whose bytes do not match its hash', function () {
    Notification::fake();
    $ada = owner();
    $workspace = workspaceOf($ada);
    $bytes = Str::random(2000);

    // The browser claims the hash of different bytes of the same size.
    $result = uploadBytes($ada, $workspace, $bytes, claimedSha: hash('sha256', Str::random(2000)));
    $upload = Upload::find($result['upload_id']);

    expect(Job::where('type', 'file.verify_hash')->sole()->output->match)->toBeFalse()
        ->and($upload->status)->toBe(UploadStatus::Failed)
        ->and(app(ObjectStorage::class)->size($upload->path))->toBeNull()
        ->and(File::count())->toBe(0);
    Notification::assertSentTo($ada, UploadRejected::class);
});

it('moves a verified temporary upload to the final path and links the file', function () {
    $ada = owner();
    $workspace = workspaceOf($ada);
    $bob = memberOf($workspace, WorkspaceRole::Editor);
    $bytes = Str::random(1500);

    // Ada's copy, made unverified again (her verify job ran straight away in tests).
    $adaFile = uploadBytes($ada, $workspace, $bytes)['file']['id'];
    File::find($adaFile)->forceFill(['verified_at' => null])->save();

    // Bob uploads the same bytes; they go to tmp/ and get verified at once.
    $bobUpload = uploadBytes($bob, $workspace, $bytes);
    expect($bobUpload['pending_verification'])->toBeTrue();

    $upload = Upload::find($bobUpload['upload_id']);
    $file = File::find($adaFile);
    expect($upload->file_id)->toBe($adaFile)
        ->and($file->verified_at)->not->toBeNull()
        ->and(app(ObjectStorage::class)->size($upload->path))->toBeNull() // tmp copy removed
        ->and(app(ObjectStorage::class)->size($file->path))->toBe(1500);
});

it('makes a 512 px WebP thumbnail for images, used by the asset grid', function () {
    $ada = owner();
    $workspace = workspaceOf($ada);
    $png = pngBytes(1200, 800);

    $fileId = uploadBytes($ada, $workspace, $png, 'poster.png', 'image/png')['file']['id'];

    $thumbJob = Job::where('type', 'file.thumbnail')->sole();
    $derivative = FileDerivative::where('source_file_id', $fileId)->sole();
    $thumb = File::find($derivative->file_id);
    $stored = app(ObjectStorage::class)->get($thumb->path);
    [$width, $height, $type] = getimagesizefromstring($stored);

    expect($thumbJob->status)->toBe(JobStatus::Succeeded)
        ->and($derivative->kind->value)->toBe('thumb')
        ->and($thumb->mime)->toBe('image/webp')
        ->and([$width, $height, $type])->toBe([512, 341, IMAGETYPE_WEBP])
        ->and(File::find($fileId)->only(['width', 'height']))->toBe(['width' => 1200, 'height' => 800]);

    // Running it again changes nothing.
    expect(app(MakeThumbnail::class)->handle($thumbJob)['skipped'])->toBe('exists');

    // The asset grid shows the thumb, not the full image.
    actingIn($ada, $workspace)->postJson('/api/v1/assets', [
        'kind' => 'image', 'name' => 'Poster', 'spec' => ['mime' => 'image/png'], 'files' => [['file_id' => $fileId, 'role' => 'image']],
    ])->assertCreated();
    expect(actingIn($ada, $workspace)->getJson('/api/v1/assets')->json('data.0.thumbnail_url'))->toContain($thumb->sha256);
});
