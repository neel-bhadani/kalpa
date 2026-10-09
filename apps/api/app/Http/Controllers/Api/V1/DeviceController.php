<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\RegisterDevice;
use App\Actions\RemoveDevice;
use App\Actions\UpdateDevice;
use App\Enums\DevicePlatform;
use App\Enums\DeviceTier;
use App\Http\Controllers\Controller;
use App\Models\UserDevice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

class DeviceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $devices = $request->user()->devices()->latest('last_seen_at')->get();

        return response()->json($devices);
    }

    public function store(Request $request, RegisterDevice $registerDevice): JsonResponse
    {
        $data = $request->validate([
            'client_id' => ['required', 'uuid'],
            'platform' => ['required', Rule::enum(DevicePlatform::class)],
            'name' => ['required', 'string', 'max:255'],
            'capabilities' => ['present', 'array'],
            'tier' => ['required', Rule::enum(DeviceTier::class)],
        ]);

        $device = $registerDevice->handle(
            $request->user(),
            $data['client_id'],
            DevicePlatform::from($data['platform']),
            $data['name'],
            $data['capabilities'],
            DeviceTier::from($data['tier']),
        );

        return response()->json($device);
    }

    public function update(Request $request, UserDevice $device, UpdateDevice $updateDevice): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'tier_override' => ['sometimes', 'nullable', Rule::enum(DeviceTier::class)],
        ]);

        return response()->json($updateDevice->handle($device, $data));
    }

    public function destroy(UserDevice $device, RemoveDevice $removeDevice): Response
    {
        $removeDevice->handle($device);

        return response()->noContent();
    }
}
