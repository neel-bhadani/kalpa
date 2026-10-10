<?php

use App\Models\ComputeWorker;

it('pairs a local worker with a one-time code', function () {
    $ada = owner();
    $workspace = workspaceOf($ada);

    $code = actingIn($ada, $workspace)->postJson('/api/v1/workers/pairing-codes')->assertCreated()->json('data.code');
    expect($code)->toMatch('/^[A-Z2-9]{4}-[A-Z2-9]{4}$/');

    $token = $this->postJson('/api/v1/workers/pair', ['code' => strtolower($code), 'name' => 'Studio PC', 'kind' => 'local', 'capabilities' => ['cpu']])
        ->assertCreated()
        ->assertJsonPath('data.worker.name', 'Studio PC')
        ->json('data.token');

    expect($token)->toStartWith('kw_')
        ->and(ComputeWorker::sole()->pairing_token_hash)->toBe(hash('sha256', $token));

    asWorker($token)->postJson('/api/v1/workers/claim')->assertOk();

    // A code works once.
    $this->postJson('/api/v1/workers/pair', ['code' => $code, 'name' => 'Again', 'kind' => 'local'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('code');
});

it('expires a pairing code after 10 minutes', function () {
    $ada = owner();
    $code = actingIn($ada, workspaceOf($ada))->postJson('/api/v1/workers/pairing-codes')->json('data.code');

    $this->travel(11)->minutes();

    $this->postJson('/api/v1/workers/pair', ['code' => $code, 'name' => 'Late', 'kind' => 'local'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('code');
});

it('registers a browser tab as a worker of its device, and runs jobs with the session', function () {
    $ada = owner();
    $workspace = workspaceOf($ada);
    $clientId = '0199d1a2-3b4c-7d5e-8f60-718293a4b5c6';

    actingIn($ada, $workspace)->postJson('/api/v1/workers/browser', ['client_id' => $clientId])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('client_id');

    actingIn($ada, $workspace)->postJson('/api/v1/devices', [
        'client_id' => $clientId, 'platform' => 'web', 'name' => 'Chrome on Linux', 'capabilities' => [], 'tier' => 'mid',
    ])->assertOk();

    $workerId = actingIn($ada, $workspace)->postJson('/api/v1/workers/browser', ['client_id' => $clientId, 'capabilities' => ['webgl2']])
        ->assertOk()
        ->assertJsonPath('data.kind', 'browser')
        ->assertJsonPath('data.name', 'Chrome on Linux')
        ->json('data.id');

    // Registering again reuses the same worker.
    actingIn($ada, $workspace)->postJson('/api/v1/workers/browser', ['client_id' => $clientId])->assertJsonPath('data.id', $workerId);

    $job = demoJob($ada, $workspace, target: 'device');
    actingIn($ada, $workspace)->withHeader('X-Worker-Id', $workerId)->postJson('/api/v1/workers/claim', ['capabilities' => ['webgl2']])
        ->assertJsonPath('data.id', $job->id);

    // Someone else's session can't use this worker id.
    $bob = owner('Bob');
    actingIn($bob, workspaceOf($bob))->withHeader('X-Worker-Id', $workerId)->postJson('/api/v1/workers/claim')->assertForbidden();
});

it('lists and removes my workers', function () {
    $ada = owner();
    $workspace = workspaceOf($ada);
    [$worker, $token] = pairedWorker($ada, $workspace);

    actingIn($ada, $workspace)->getJson('/api/v1/workers')->assertJsonPath('data.0.id', $worker->id);
    actingIn($ada, $workspace)->deleteJson("/api/v1/workers/{$worker->id}")->assertNoContent();
    actingIn($ada, $workspace)->getJson('/api/v1/workers')->assertJsonCount(0, 'data');

    asWorker($token)->postJson('/api/v1/workers/claim')->assertUnauthorized();
});
