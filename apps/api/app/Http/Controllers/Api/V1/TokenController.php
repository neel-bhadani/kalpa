<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\CreateApiToken;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class TokenController extends Controller
{
    public function store(Request $request, CreateApiToken $createApiToken): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['required', 'string', 'max:255'],
        ]);

        $token = $createApiToken->handle($data['email'], $data['password'], $data['device_name']);

        return response()->json(['data' => [
            'id' => $token->accessToken->id,
            'token' => $token->plainTextToken,
        ]], 201);
    }

    public function destroy(Request $request, string $id): Response
    {
        $deleted = $request->user()->tokens()->whereKey($id)->delete();

        abort_if($deleted === 0, 404);

        return response()->noContent();
    }
}
