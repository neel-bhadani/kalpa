<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\DeviceController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\ProjectController;
use App\Http\Controllers\Api\V1\ProjectMemoryController;
use App\Http\Controllers\Api\V1\TaskController;
use App\Http\Controllers\Api\V1\TaskDependencyController;
use App\Http\Controllers\Api\V1\TokenController;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthController::class);

Route::prefix('auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');
    Route::post('/tokens', [TokenController::class, 'store'])->middleware('throttle:login');

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::delete('/tokens/{id}', [TokenController::class, 'destroy'])->whereUuid('id');
    });
});

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/me', [AuthController::class, 'me']);

    Route::get('/devices', [DeviceController::class, 'index']);
    Route::post('/devices', [DeviceController::class, 'store']);
    Route::patch('/devices/{device}', [DeviceController::class, 'update'])->whereUuid('device')->can('update', 'device');
    Route::delete('/devices/{device}', [DeviceController::class, 'destroy'])->whereUuid('device')->can('delete', 'device');
});

// Workspace-scoped: needs the X-Workspace-Id header (see EnsureWorkspaceMember).
Route::middleware(['auth:sanctum', 'workspace'])->group(function () {
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
