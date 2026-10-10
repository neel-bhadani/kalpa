<?php

namespace App\Http\Controllers\Api\V1;

use App\Core\Sync\CurrentDevice;
use App\Enums\DeviceTier;
use App\Http\Controllers\Controller;
use App\Http\Resources\RegisteredModelResource;
use App\Models\RegisteredModel;
use Illuminate\Http\JsonResponse;

class ModelController extends Controller
{
    private const TIER_RANK = ['low' => 0, 'mid' => 1, 'high' => 2];

    /**
     * The model registry. With X-Device-Id, each model says whether it fits this device:
     * it can run on a device at all, and the device's tier (its override first) is at least the model's.
     */
    public function index(CurrentDevice $device): JsonResponse
    {
        $tier = $device->get() ? ($device->get()->tier_override ?? $device->get()->tier) : null;

        $models = RegisteredModel::orderBy('capability')->orderBy('size_mb')->get()
            ->map(fn (RegisteredModel $model) => new RegisteredModelResource($model, $tier === null ? null : $this->fits($model, $tier)));

        return response()->json(['data' => $models]);
    }

    private function fits(RegisteredModel $model, DeviceTier $tier): bool
    {
        return in_array('device', $model->targets, true)
            && self::TIER_RANK[$tier->value] >= self::TIER_RANK[$model->min_tier->value];
    }
}
