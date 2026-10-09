<?php

namespace App\Actions;

use App\Core\Storage\ObjectStorage;
use App\Enums\UploadStatus;
use App\Models\File;
use App\Models\Upload;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CreateUpload
{
    public function __construct(private ObjectStorage $storage) {}

    /**
     * Start a multipart upload, or return the existing file when the workspace already has this content.
     *
     * @return array{upload: ?Upload, file: ?File}
     *
     * @throws ValidationException for a type that isn't allowed or a file that's too big
     */
    public function handle(Workspace $workspace, User $user, string $fileName, int $sizeBytes, ?string $mime, string $sha256): array
    {
        $type = $this->allowedType($fileName, $mime);

        if ($sizeBytes > config('uploads.max_bytes')) {
            $limit = round(config('uploads.max_bytes') / 1024 ** 3, 1);
            throw ValidationException::withMessages(['size_bytes' => "Files can be at most {$limit} GB."]);
        }

        $existing = File::where('workspace_id', $workspace->id)->where('sha256', $sha256)->first();
        if ($existing !== null) {
            return ['upload' => null, 'file' => $existing];
        }

        $path = File::pathFor($workspace->id, $sha256);

        $upload = Upload::create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'file_name' => $fileName,
            'size_bytes' => $sizeBytes,
            'mime' => $type,
            'sha256' => $sha256,
            'part_size' => config('uploads.part_size'),
            's3_upload_id' => $this->storage->startMultipart($path, $type),
            'path' => $path,
            'status' => UploadStatus::Pending,
            'expires_at' => now()->addHours(config('uploads.expire_hours')),
        ]);

        return ['upload' => $upload, 'file' => null];
    }

    /**
     * The type is decided by the extension; the browser's type must not contradict it.
     */
    private function allowedType(string $fileName, ?string $mime): string
    {
        $extension = Str::lower(pathinfo($fileName, PATHINFO_EXTENSION));
        $type = config('uploads.types')[$extension] ?? null;

        // Browsers often send "" or octet-stream for 3D and text files; otherwise the family must match.
        $browserFamily = Str::before((string) $mime, '/');
        $mimeOk = in_array($mime, [null, '', 'application/octet-stream'], true)
            || $mime === $type
            || ($type !== null && $browserFamily === Str::before($type, '/'));

        if ($type === null || ! $mimeOk) {
            throw ValidationException::withMessages([
                'file_name' => 'This file type is not allowed. Use images, audio, video, 3D (glb, gltf, fbx, obj, bvh), text or PDF.',
            ]);
        }

        return $type;
    }
}
