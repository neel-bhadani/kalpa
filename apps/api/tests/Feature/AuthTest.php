<?php

use App\Actions\RegisterUser;
use App\Enums\WorkspaceRole;
use App\Models\User;

// Requests from the web app carry its origin, so Sanctum uses the cookie session.
beforeEach(function () {
    $this->withHeader('Origin', 'http://localhost:8089');
});

function registerAda(): User
{
    return app(RegisterUser::class)->handle('Ada', 'ada@example.com', 'secret-password');
}

it('registers a user with a personal workspace they own', function () {
    $this->postJson('/api/v1/auth/register', [
        'name' => 'Ada',
        'email' => 'ada@example.com',
        'password' => 'secret-password',
    ])
        ->assertCreated()
        ->assertJsonPath('user.name', 'Ada')
        ->assertJsonPath('workspaces.0.name', "Ada's workspace")
        ->assertJsonPath('workspaces.0.role', 'owner');

    $user = User::where('email', 'ada@example.com')->sole();
    $workspace = $user->workspaces()->sole();

    expect($workspace->owner_id)->toBe($user->id)
        ->and($workspace->pivot->role)->toBe(WorkspaceRole::Owner);
});

it('rejects a duplicate email in a different case', function () {
    registerAda();

    $this->postJson('/api/v1/auth/register', [
        'name' => 'Ada Again',
        'email' => 'ADA@Example.com',
        'password' => 'secret-password',
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('email');

    expect(User::count())->toBe(1);
});

it('logs in, shows me and logs out with the cookie session', function () {
    registerAda();

    $this->postJson('/api/v1/auth/login', [
        'email' => 'ada@example.com',
        'password' => 'secret-password',
    ])->assertOk();

    $this->getJson('/api/v1/me')
        ->assertOk()
        ->assertJsonPath('user.email', 'ada@example.com')
        ->assertJsonPath('workspaces.0.role', 'owner');

    $this->postJson('/api/v1/auth/logout')->assertNoContent();

    $this->assertGuest('web');

    // The test app reuses guards between requests; a real request starts fresh.
    $this->app['auth']->forgetGuards();
    $this->getJson('/api/v1/me')->assertUnauthorized();
});

it('returns 422 for a wrong password', function () {
    registerAda();

    $this->postJson('/api/v1/auth/login', [
        'email' => 'ada@example.com',
        'password' => 'wrong-password',
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('email');

    $this->assertGuest('web');
});

it('returns 401 for me without auth', function () {
    $this->getJson('/api/v1/me')->assertUnauthorized();
});

it('accepts a token from auth/tokens on me', function () {
    registerAda();

    $token = $this->postJson('/api/v1/auth/tokens', [
        'email' => 'ada@example.com',
        'password' => 'secret-password',
        'device_name' => 'Ada laptop',
    ])
        ->assertCreated()
        ->json('token');

    $this->withHeaders(['Origin' => ''])
        ->withToken($token)
        ->getJson('/api/v1/me')
        ->assertOk()
        ->assertJsonPath('user.email', 'ada@example.com');
});

it('deletes a token', function () {
    $user = registerAda();
    $token = $user->createToken('Ada laptop');

    $this->withHeaders(['Origin' => ''])
        ->withToken($token->plainTextToken)
        ->deleteJson("/api/v1/auth/tokens/{$token->accessToken->id}")
        ->assertNoContent();

    expect($user->tokens()->count())->toBe(0);
});

it('rate limits login to 5 attempts per minute', function () {
    registerAda();

    foreach (range(1, 5) as $attempt) {
        $this->postJson('/api/v1/auth/login', [
            'email' => 'ada@example.com',
            'password' => 'wrong-password',
        ])->assertUnprocessable();
    }

    $this->postJson('/api/v1/auth/login', [
        'email' => 'ada@example.com',
        'password' => 'secret-password',
    ])->assertTooManyRequests();
});
