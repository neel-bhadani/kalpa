<?php

use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as Router;
use Illuminate\Support\Str;

/*
| Every /api/v1 route, checked by what it actually does, so a new route can't slip through.
*/

// Open to anyone: health, sign-up and sign-in, and a local worker trading its pairing code.
const PUBLIC_ROUTES = [
    'GET api/v1/health',
    'POST api/v1/auth/register',
    'POST api/v1/auth/login',
    'POST api/v1/auth/tokens',
    'POST api/v1/workers/pair',
];

// Signed-in routes about the user themselves (no workspace data): their profile, devices,
// notifications and own AI providers. Everything else must name a workspace.
const USER_ROUTES = [
    'POST api/v1/auth/logout',
    'DELETE api/v1/auth/tokens/{id}',
    'GET api/v1/me',
    'GET api/v1/devices',
    'POST api/v1/devices',
    'PATCH api/v1/devices/{device}',
    'DELETE api/v1/devices/{device}',
    'GET api/v1/notifications',
    'POST api/v1/notifications/read',
    'GET api/v1/ai/providers',
    'POST api/v1/ai/providers',
    'PATCH api/v1/ai/providers/{provider}',
    'DELETE api/v1/ai/providers/{provider}',
    'POST api/v1/ai/providers/{provider}/chat',
];

// The claim protocol: a worker token or a browser tab's session plus X-Worker-Id, checked by AuthenticateWorker.
const WORKER_ROUTES = [
    'POST api/v1/workers/claim',
    'POST api/v1/jobs/{job}/heartbeat',
    'POST api/v1/jobs/{job}/complete',
    'POST api/v1/jobs/{job}/fail',
];

/**
 * @return array<string, Route> "METHOD uri" => route
 */
function apiRoutes(): array
{
    $routes = [];
    foreach (Router::getRoutes() as $route) {
        if (! str_starts_with($route->uri(), 'api/v1')) {
            continue;
        }
        foreach (array_diff($route->methods(), ['HEAD']) as $method) {
            $routes["{$method} {$route->uri()}"] = $route;
        }
    }

    return $routes;
}

// A URL for the route with made-up UUIDs (or numbers) for its parameters.
function urlFor(Route $route): string
{
    return '/'.preg_replace_callback('/\{(\w+)\??\}/', fn ($m) => $m[1] === 'number' ? '1' : (string) Str::uuid7(), $route->uri());
}

it('knows every route (update the lists above when adding one)', function () {
    $listed = [...PUBLIC_ROUTES, ...USER_ROUTES, ...WORKER_ROUTES];
    $unlisted = array_filter(array_keys(apiRoutes()), fn ($key) => ! in_array($key, $listed, true) && ! in_array('workspace', apiRoutes()[$key]->gatherMiddleware(), true));

    expect(array_values($unlisted))->toBe([]);
    expect(array_diff($listed, array_keys(apiRoutes())))->toBe([]);
});

it('refuses every non-public route without sign-in', function () {
    foreach (apiRoutes() as $key => $route) {
        if (in_array($key, PUBLIC_ROUTES, true)) {
            continue;
        }
        [$method] = explode(' ', $key);
        expect($this->json($method, urlFor($route))->status())->toBe(401, "{$key} answered without sign-in");
    }
});

it('sends every workspace route through the workspace check', function () {
    $ada = owner();
    $workspace = workspaceOf($ada);
    $stranger = owner('Stranger');

    foreach (apiRoutes() as $key => $route) {
        if (! in_array('workspace', $route->gatherMiddleware(), true)) {
            continue;
        }
        [$method] = explode(' ', $key);

        // No workspace named: refused before anything else happens.
        $this->flushHeaders()->actingAs($ada)->json($method, urlFor($route))->assertStatus(400);
        // Someone else's workspace: refused.
        $this->flushHeaders()->actingAs($stranger)->withHeader('X-Workspace-Id', $workspace->id)->json($method, urlFor($route))->assertForbidden();
    }
});

it('checks workers on every claim-protocol route', function () {
    foreach (WORKER_ROUTES as $key) {
        expect(apiRoutes()[$key]->gatherMiddleware())->toContain('worker');
    }
});

it('rate-limits sign-in, sign-up, uploads, pairing and sync', function () {
    $limits = [
        'POST api/v1/auth/login' => 'throttle:login',
        'POST api/v1/auth/tokens' => 'throttle:login',
        'POST api/v1/auth/register' => 'throttle:register',
        'POST api/v1/uploads' => 'throttle:uploads',
        'POST api/v1/workers/pair' => 'throttle:pairing',
        'GET api/v1/sync' => 'throttle:sync',
        'POST api/v1/sync' => 'throttle:sync',
        'POST api/v1/sync/unsynced' => 'throttle:sync',
    ];

    foreach ($limits as $key => $middleware) {
        expect(apiRoutes()[$key]->gatherMiddleware())->toContain($middleware);
    }

    // And they bite: the 11th sign-up in a minute from one address is refused.
    foreach (range(1, 10) as $i) {
        $this->postJson('/api/v1/auth/register', ['name' => "U{$i}", 'email' => "u{$i}@example.com", 'password' => 'secret-password'])->assertCreated();
        $this->app['auth']->forgetGuards();
    }
    $this->postJson('/api/v1/auth/register', ['name' => 'U11', 'email' => 'u11@example.com', 'password' => 'secret-password'])->assertTooManyRequests();
});
