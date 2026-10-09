<?php

use App\Models\User;
use App\Models\UserDevice;

function devicePayload(array $overrides = []): array
{
    return array_merge([
        'client_id' => '0199d1a2-3b4c-7d5e-8f60-718293a4b5c6',
        'platform' => 'web',
        'name' => 'Chrome on Linux',
        'capabilities' => ['memory_gb' => 8, 'cores' => 6, 'webgl2' => true],
        'tier' => 'mid',
    ], $overrides);
}

it('registers a device and is idempotent', function () {
    $user = User::factory()->create();

    $first = $this->actingAs($user)->postJson('/api/v1/devices', devicePayload())
        ->assertOk()
        ->assertJsonPath('tier', 'mid')
        ->json();

    $this->travel(5)->minutes();

    $second = $this->actingAs($user)->postJson('/api/v1/devices', devicePayload(['tier' => 'low', 'name' => 'Other name']))
        ->assertOk()
        ->json();

    expect(UserDevice::count())->toBe(1)
        ->and($second['id'])->toBe($first['id'])
        ->and($second['tier'])->toBe('low')
        ->and($second['name'])->toBe('Chrome on Linux')
        ->and($second['last_seen_at'])->not->toBe($first['last_seen_at']);
});

it('brings back a removed device when it checks in again', function () {
    $user = User::factory()->create();
    $id = $this->actingAs($user)->postJson('/api/v1/devices', devicePayload())->json('id');

    $this->actingAs($user)->deleteJson("/api/v1/devices/{$id}")->assertNoContent();
    $this->actingAs($user)->postJson('/api/v1/devices', devicePayload())->assertOk()->assertJsonPath('id', $id);

    expect(UserDevice::count())->toBe(1);
});

it('validates the device', function () {
    $this->actingAs(User::factory()->create())
        ->postJson('/api/v1/devices', devicePayload(['platform' => 'fridge', 'tier' => 'ultra', 'client_id' => 'nope']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['platform', 'tier', 'client_id']);
});

it('lists, updates and removes my own devices', function () {
    $user = User::factory()->create();
    $id = $this->actingAs($user)->postJson('/api/v1/devices', devicePayload())->json('id');

    $this->actingAs($user)->getJson('/api/v1/devices')
        ->assertOk()
        ->assertJsonCount(1)
        ->assertJsonPath('0.id', $id);

    $this->actingAs($user)->patchJson("/api/v1/devices/{$id}", ['name' => 'Work PC', 'tier_override' => 'high'])
        ->assertOk()
        ->assertJsonPath('name', 'Work PC')
        ->assertJsonPath('tier_override', 'high');

    $this->actingAs($user)->patchJson("/api/v1/devices/{$id}", ['tier_override' => null])
        ->assertOk()
        ->assertJsonPath('tier_override', null);

    $this->actingAs($user)->deleteJson("/api/v1/devices/{$id}")->assertNoContent();
    $this->actingAs($user)->getJson('/api/v1/devices')->assertJsonCount(0);
});

it("does not let a user see, edit or delete another user's device", function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $id = $this->actingAs($owner)->postJson('/api/v1/devices', devicePayload())->json('id');

    $this->actingAs($other)->getJson('/api/v1/devices')->assertOk()->assertJsonCount(0);
    $this->actingAs($other)->patchJson("/api/v1/devices/{$id}", ['name' => 'Mine now'])->assertForbidden();
    $this->actingAs($other)->deleteJson("/api/v1/devices/{$id}")->assertForbidden();

    expect(UserDevice::find($id)->name)->toBe('Chrome on Linux');
});

it('requires auth for devices', function () {
    $this->getJson('/api/v1/devices')->assertUnauthorized();
    $this->postJson('/api/v1/devices', devicePayload())->assertUnauthorized();
});
