<?php

use App\Http\Controllers\Api\V1\AiController;
use App\Http\Controllers\Api\V1\AiProviderController;
use App\Http\Controllers\Api\V1\AssetController;
use App\Http\Controllers\Api\V1\AssetLinkController;
use App\Http\Controllers\Api\V1\AssetVersionController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\DeviceController;
use App\Http\Controllers\Api\V1\FileController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\JobController;
use App\Http\Controllers\Api\V1\ModelController;
use App\Http\Controllers\Api\V1\ModuleController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\ProjectAssetController;
use App\Http\Controllers\Api\V1\ProjectController;
use App\Http\Controllers\Api\V1\ProjectMemoryController;
use App\Http\Controllers\Api\V1\SyncController;
use App\Http\Controllers\Api\V1\TaskController;
use App\Http\Controllers\Api\V1\TaskDependencyController;
use App\Http\Controllers\Api\V1\TaskOutputController;
use App\Http\Controllers\Api\V1\TokenController;
use App\Http\Controllers\Api\V1\UploadController;
use App\Http\Controllers\Api\V1\WorkerController;
use App\Http\Controllers\Api\V1\WorkerJobController;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthController::class);

Route::prefix('auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:register');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');
    Route::post('/tokens', [TokenController::class, 'store'])->middleware('throttle:login');

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::delete('/tokens/{id}', [TokenController::class, 'destroy'])->whereUuid('id');
    });
});

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/me', [AuthController::class, 'me']);

    // My own AI providers (not workspace-scoped).
    Route::get('/ai/providers', [AiProviderController::class, 'index']);
    Route::post('/ai/providers', [AiProviderController::class, 'store']);
    Route::patch('/ai/providers/{provider}', [AiProviderController::class, 'update'])->whereUuid('provider');
    Route::delete('/ai/providers/{provider}', [AiProviderController::class, 'destroy'])->whereUuid('provider');

    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::post('/notifications/read', [NotificationController::class, 'markAllRead']);

    Route::get('/devices', [DeviceController::class, 'index']);
    Route::post('/devices', [DeviceController::class, 'store']);
    Route::patch('/devices/{device}', [DeviceController::class, 'update'])->whereUuid('device')->can('update', 'device');
    Route::delete('/devices/{device}', [DeviceController::class, 'destroy'])->whereUuid('device')->can('delete', 'device');
});

// Workspace-scoped: needs the X-Workspace-Id header (see EnsureWorkspaceMember).
Route::middleware(['auth:sanctum', 'workspace'])->group(function () {
    Route::get('/modules', [ModuleController::class, 'index']);
    Route::patch('/modules/{key}', [ModuleController::class, 'update']);

    Route::get('/dashboard', DashboardController::class);

    Route::get('/models', [ModelController::class, 'index']);

    Route::whereUuid(['task', 'session', 'step', 'project'])->group(function () {
        Route::get('/tasks/{task}/ai/sessions', [AiController::class, 'sessions']);
        Route::post('/tasks/{task}/ai/sessions', [AiController::class, 'start']);
        Route::get('/ai/sessions/{session}', [AiController::class, 'show']);
        Route::patch('/ai/sessions/{session}', [AiController::class, 'update']);
        Route::post('/ai/sessions/{session}/messages', [AiController::class, 'addMessage']);
        Route::post('/ai/sessions/{session}/steps', [AiController::class, 'addStep']);

        Route::patch('/ai/steps/{step}', [AiController::class, 'updateStep']);
        Route::post('/ai/steps/{step}/approve', [AiController::class, 'approve']);
        Route::post('/ai/steps/{step}/reject', [AiController::class, 'reject']);
        Route::post('/ai/steps/{step}/undo', [AiController::class, 'undo']);
        Route::post('/ai/steps/{step}/retry', [AiController::class, 'retry']);

        Route::post('/projects/{project}/plans', [AiController::class, 'plan']);
    });

    Route::get('/sync', [SyncController::class, 'pull'])->middleware('throttle:sync');
    Route::post('/sync', [SyncController::class, 'push'])->middleware('throttle:sync');
    Route::post('/sync/unsynced', [SyncController::class, 'unsynced'])->middleware('throttle:sync');

    Route::whereUuid(['job', 'worker'])->group(function () {
        Route::get('/jobs', [JobController::class, 'index']);
        Route::post('/jobs', [JobController::class, 'store']);
        Route::get('/jobs/{job}', [JobController::class, 'show']);
        Route::post('/jobs/{job}/cancel', [JobController::class, 'cancel']);
        Route::post('/jobs/{job}/pause', [JobController::class, 'pause']);
        Route::post('/jobs/{job}/resume', [JobController::class, 'resume']);
        Route::post('/jobs/{job}/retry', [JobController::class, 'retry']);

        Route::get('/workers', [WorkerController::class, 'index']);
        Route::delete('/workers/{worker}', [WorkerController::class, 'destroy']);
        Route::post('/workers/pairing-codes', [WorkerController::class, 'pairingCode']);
        Route::post('/workers/browser', [WorkerController::class, 'registerBrowser']);
    });

    Route::whereUuid(['upload', 'file'])->group(function () {
        Route::post('/uploads', [UploadController::class, 'store'])->middleware('throttle:uploads');
        Route::get('/uploads/{upload}', [UploadController::class, 'show']);
        Route::post('/uploads/{upload}/parts', [UploadController::class, 'parts']);
        Route::post('/uploads/{upload}/complete', [UploadController::class, 'complete']);
        Route::delete('/uploads/{upload}', [UploadController::class, 'destroy']);

        Route::get('/files/{file}/url', [FileController::class, 'url']);
    });

    Route::whereUuid(['asset', 'project', 'task'])->whereNumber('number')->group(function () {
        Route::get('/assets', [AssetController::class, 'index']);
        Route::post('/assets', [AssetController::class, 'store']);
        Route::get('/assets/{asset}', [AssetController::class, 'show']);
        Route::patch('/assets/{asset}', [AssetController::class, 'update']);
        Route::delete('/assets/{asset}', [AssetController::class, 'destroy']);

        Route::get('/assets/{asset}/versions', [AssetVersionController::class, 'index']);
        Route::post('/assets/{asset}/versions', [AssetVersionController::class, 'store']);
        Route::get('/assets/{asset}/versions/{number}', [AssetVersionController::class, 'show']);
        Route::post('/assets/{asset}/restore/{number}', [AssetVersionController::class, 'restore']);

        Route::get('/assets/{asset}/links', [AssetLinkController::class, 'index']);
        Route::post('/assets/{asset}/links', [AssetLinkController::class, 'store']);
        Route::delete('/assets/{asset}/links', [AssetLinkController::class, 'destroy']);

        Route::get('/projects/{project}/assets', [ProjectAssetController::class, 'index']);
        Route::post('/projects/{project}/assets', [ProjectAssetController::class, 'store']);
        Route::delete('/projects/{project}/assets', [ProjectAssetController::class, 'destroy']);

        Route::get('/tasks/{task}/outputs', [TaskOutputController::class, 'index']);
    });

    Route::whereUuid(['project', 'task'])->group(function () {
        Route::get('/projects', [ProjectController::class, 'index']);
        Route::post('/projects', [ProjectController::class, 'store']);
        Route::get('/projects/{project}', [ProjectController::class, 'show']);
        Route::patch('/projects/{project}', [ProjectController::class, 'update']);
        Route::delete('/projects/{project}', [ProjectController::class, 'destroy']);
        Route::post('/projects/{project}/open', [ProjectController::class, 'open']);

        Route::get('/projects/{project}/memory', [ProjectMemoryController::class, 'show']);
        Route::patch('/projects/{project}/memory', [ProjectMemoryController::class, 'update']);
        Route::get('/projects/{project}/memory/versions', [ProjectMemoryController::class, 'versions']);

        Route::get('/tasks', [TaskController::class, 'index']);
        Route::post('/projects/{project}/tasks', [TaskController::class, 'store']);
        Route::get('/tasks/{task}', [TaskController::class, 'show']);
        Route::patch('/tasks/{task}', [TaskController::class, 'update']);
        Route::delete('/tasks/{task}', [TaskController::class, 'destroy']);
        Route::post('/tasks/{task}/status', [TaskController::class, 'status']);
        Route::put('/tasks/{task}/checkpoint', [TaskController::class, 'checkpoint']);
        Route::get('/tasks/{task}/events', [TaskController::class, 'events']);

        Route::post('/tasks/{task}/dependencies', [TaskDependencyController::class, 'store']);
        Route::delete('/tasks/{task}/dependencies', [TaskDependencyController::class, 'destroy']);
    });
});

// A local worker trades a pairing code for its token (no login yet).
Route::post('/workers/pair', [WorkerController::class, 'pair'])->middleware('throttle:pairing');

// The claim protocol: paired workers (kw_ token) and browser tabs (session + X-Worker-Id).
Route::middleware('worker')->whereUuid('job')->group(function () {
    Route::post('/workers/claim', [WorkerJobController::class, 'claim']);
    Route::post('/jobs/{job}/heartbeat', [WorkerJobController::class, 'heartbeat']);
    Route::post('/jobs/{job}/complete', [WorkerJobController::class, 'complete']);
    Route::post('/jobs/{job}/fail', [WorkerJobController::class, 'fail']);
});
