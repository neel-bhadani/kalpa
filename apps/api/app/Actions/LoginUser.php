<?php

namespace App\Actions;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class LoginUser
{
    /**
     * Start a cookie session for the web app.
     *
     * @throws ValidationException when the email or password is wrong
     */
    public function handle(Request $request, string $email, string $password): User
    {
        if (! Auth::guard('web')->attempt(['email' => $email, 'password' => $password])) {
            throw ValidationException::withMessages([
                'email' => __('auth.failed'),
            ]);
        }

        $request->session()->regenerate();

        return Auth::guard('web')->user();
    }
}
