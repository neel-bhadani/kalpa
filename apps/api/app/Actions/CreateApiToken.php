<?php

namespace App\Actions;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\NewAccessToken;

class CreateApiToken
{
    /**
     * Issue a token for a desktop or mobile app, named after the device.
     *
     * @throws ValidationException when the email or password is wrong
     */
    public function handle(string $email, string $password, string $deviceName): NewAccessToken
    {
        $user = User::where('email', $email)->first();

        if (! $user || ! Hash::check($password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => __('auth.failed'),
            ]);
        }

        return $user->createToken($deviceName);
    }
}
