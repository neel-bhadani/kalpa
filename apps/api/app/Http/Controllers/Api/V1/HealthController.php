<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\CheckHealth;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class HealthController extends Controller
{
    public function __invoke(CheckHealth $checkHealth): JsonResponse
    {
        $status = $checkHealth->handle();
        $allOk = ! in_array('down', $status, true);

        return response()->json($status, $allOk ? 200 : 503);
    }
}
