<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\CreatePairingCode;
use App\Actions\PairWorker;
use App\Actions\RegisterBrowserWorker;
use App\Actions\RemoveWorker;
use App\Core\Workspaces\CurrentWorkspace;
use App\Enums\WorkerKind;
use App\Http\Controllers\Controller;
use App\Http\Resources\WorkerResource;
use App\Models\ComputeWorker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * My workers: list, remove, pair a local worker, register this browser tab.
 */
class WorkerController extends Controller
{
    public function index(Request $request, CurrentWorkspace $current): JsonResponse
    {
        $workers = ComputeWorker::where('workspace_id', $current->id())
            ->where('user_id', $request->user()->id)
            ->orderByDesc('last_heartbeat_at')
            ->get();

        return response()->json(['data' => WorkerResource::collection($workers)]);
    }

    public function destroy(ComputeWorker $worker, RemoveWorker $removeWorker): Response
    {
        Gate::authorize('delete', $worker);

        $removeWorker->handle($worker);

        return response()->noContent();
    }

    public function pairingCode(Request $request, CurrentWorkspace $current, CreatePairingCode $createCode): JsonResponse
    {
        Gate::authorize('create', ComputeWorker::class);

        return response()->json(['data' => $createCode->handle($request->user(), $current->get())], 201);
    }

    /**
     * Called by the local worker (no login): a pairing code for a worker token. The token is shown once.
     */
    public function pair(Request $request, PairWorker $pairWorker): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:20'],
            'name' => ['required', 'string', 'max:255'],
            'kind' => ['required', Rule::in([WorkerKind::Local->value, WorkerKind::Desktop->value])],
            'capabilities' => ['sometimes', 'array', 'list'],
            'capabilities.*' => ['string', 'max:50'],
        ]);

        $result = $pairWorker->handle($data['code'], $data['name'], WorkerKind::from($data['kind']), $data['capabilities'] ?? []);

        return response()->json(['data' => [
            'worker' => new WorkerResource($result['worker']),
            'token' => $result['token'],
        ]], 201);
    }

    public function registerBrowser(Request $request, CurrentWorkspace $current, RegisterBrowserWorker $register): JsonResponse
    {
        Gate::authorize('create', ComputeWorker::class);

        $data = $request->validate([
            'client_id' => ['required', 'uuid'],
            'capabilities' => ['sometimes', 'array', 'list'],
            'capabilities.*' => ['string', 'max:50'],
        ]);

        $worker = $register->handle($request->user(), $current->get(), $data['client_id'], $data['capabilities'] ?? []);

        return response()->json(['data' => new WorkerResource($worker)]);
    }
}
