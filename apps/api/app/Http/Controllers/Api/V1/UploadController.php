<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\AbortUpload;
use App\Actions\CompleteUpload;
use App\Actions\CreateUpload;
use App\Actions\PresignUploadParts;
use App\Core\Storage\ObjectStorage;
use App\Core\Workspaces\CurrentWorkspace;
use App\Http\Controllers\Controller;
use App\Http\Resources\FileResource;
use App\Http\Resources\UploadResource;
use App\Models\Upload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * Resumable uploads: the browser sends the bytes straight to storage with presigned URLs.
 */
class UploadController extends Controller
{
    public function store(Request $request, CurrentWorkspace $current, CreateUpload $createUpload): JsonResponse
    {
        Gate::authorize('create', Upload::class);

        $data = $request->validate([
            'file_name' => ['required', 'string', 'max:255'],
            'size_bytes' => ['required', 'integer', 'min:1'],
            'mime' => ['nullable', 'string', 'max:255'],
            'sha256' => ['required', 'string', 'regex:/^[0-9a-f]{64}$/'],
        ], ['sha256.regex' => 'The sha256 must be 64 lowercase hex characters.']);

        $result = $createUpload->handle(
            $current->get(), $request->user(), $data['file_name'], $data['size_bytes'], $data['mime'] ?? null, $data['sha256'],
        );

        if ($result['file'] !== null) {
            return response()->json(['data' => ['file' => new FileResource($result['file']), 'deduplicated' => true]]);
        }

        return response()->json(['data' => ['upload' => new UploadResource($result['upload']), 'deduplicated' => false]], 201);
    }

    /**
     * The upload and the parts already stored, so a reloaded page can skip them.
     */
    public function show(Upload $upload, ObjectStorage $storage): JsonResponse
    {
        Gate::authorize('update', $upload);

        $parts = $upload->isOpen() ? array_values($storage->listParts($upload->path, $upload->s3_upload_id)) : [];

        return response()->json(['data' => ['upload' => new UploadResource($upload), 'parts' => $parts]]);
    }

    public function parts(Request $request, Upload $upload, PresignUploadParts $presign): JsonResponse
    {
        Gate::authorize('update', $upload);

        $data = $request->validate([
            'part_numbers' => ['required', 'array', 'list', 'min:1', 'max:100'],
            'part_numbers.*' => ['integer', 'distinct'],
        ]);

        return response()->json(['data' => $presign->handle($upload, $data['part_numbers'])]);
    }

    public function complete(Request $request, Upload $upload, CompleteUpload $completeUpload): JsonResponse
    {
        Gate::authorize('update', $upload);

        $data = $request->validate([
            'parts' => ['required', 'array', 'list', 'min:1'],
            'parts.*.number' => ['required', 'integer', 'min:1', 'distinct'],
            'parts.*.etag' => ['required', 'string', 'max:255'],
        ]);

        $file = $completeUpload->handle($upload, $data['parts']);

        // A temporary upload has no file until its hash is verified (P0 step 7).
        return response()->json(['data' => [
            'file' => $file ? new FileResource($file) : null,
            'deduplicated' => false,
            'pending_verification' => $file === null,
        ]]);
    }

    public function destroy(Upload $upload, AbortUpload $abortUpload): Response
    {
        Gate::authorize('update', $upload);

        $abortUpload->handle($upload);

        return response()->noContent();
    }
}
