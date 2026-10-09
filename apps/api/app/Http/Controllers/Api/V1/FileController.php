<?php

namespace App\Http\Controllers\Api\V1;

use App\Core\Storage\ObjectStorage;
use App\Http\Controllers\Controller;
use App\Models\File;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class FileController extends Controller
{
    /**
     * A short-lived download URL for a workspace member.
     */
    public function url(File $file, ObjectStorage $storage): JsonResponse
    {
        Gate::authorize('view', $file);

        $expires = now()->addMinutes(config('uploads.download_url_minutes'));

        return response()->json(['data' => [
            'url' => $storage->presignGet($file->path, $file->mime, $expires),
            'expires_at' => $expires,
        ]]);
    }
}
