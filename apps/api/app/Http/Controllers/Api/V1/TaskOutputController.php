<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\TaskOutputResource;
use App\Models\Task;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class TaskOutputController extends Controller
{
    public function index(Task $task): JsonResponse
    {
        Gate::authorize('view', $task);

        $outputs = $task->outputs()
            ->whereHas('asset')
            ->with('asset.currentVersion.assetFiles.file.thumb.file')
            ->orderBy('created_at')
            ->get();

        return response()->json(['data' => TaskOutputResource::collection($outputs)]);
    }
}
