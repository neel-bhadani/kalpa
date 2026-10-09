<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\LoginUser;
use App\Actions\LogoutUser;
use App\Actions\RegisterUser;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;

class AuthController extends Controller
{
    public function register(Request $request, RegisterUser $registerUser): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', Password::min(8)],
        ]);

        $user = $registerUser->handle($data['name'], $data['email'], $data['password']);

        // Sign the web app in straight away.
        if ($request->hasSession()) {
            Auth::guard('web')->login($user);
            $request->session()->regenerate();
        }

        return response()->json($this->profile($user), 201);
    }

    public function login(Request $request, LoginUser $loginUser): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = $loginUser->handle($request, $data['email'], $data['password']);

        return response()->json($this->profile($user));
    }

    public function logout(Request $request, LogoutUser $logoutUser): Response
    {
        $logoutUser->handle($request);

        return response()->noContent();
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json($this->profile($request->user()));
    }

    /**
     * The signed-in user and the workspaces they belong to, with their role.
     */
    private function profile(User $user): array
    {
        $workspaces = $user->workspaces()->orderBy('workspaces.created_at')->get();

        return [
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'locale' => $user->locale,
                'settings' => (object) $user->settings,
            ],
            'workspaces' => $workspaces->map(fn ($workspace) => [
                'id' => $workspace->id,
                'name' => $workspace->name,
                'plan' => $workspace->plan,
                'role' => $workspace->pivot->role,
            ]),
        ];
    }
}
