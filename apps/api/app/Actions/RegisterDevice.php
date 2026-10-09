<?php

namespace App\Actions;

use App\Enums\DevicePlatform;
use App\Enums\DeviceTier;
use App\Models\User;
use App\Models\UserDevice;

class RegisterDevice
{
    /**
     * Create the device the first time a client checks in, or refresh it later.
     * The name is only set on creation, so a name the user chose is kept.
     *
     * @param  array<string, mixed>  $capabilities
     */
    public function handle(
        User $user,
        string $clientId,
        DevicePlatform $platform,
        string $name,
        array $capabilities,
        DeviceTier $tier,
    ): UserDevice {
        // Safe when two requests race: firstOrCreate falls back to a select on a unique clash.
        $device = UserDevice::withTrashed()->firstOrCreate(
            ['user_id' => $user->id, 'client_id' => $clientId],
            ['platform' => $platform, 'name' => $name, 'capabilities' => $capabilities, 'tier' => $tier],
        );

        $device->fill([
            'platform' => $platform,
            'capabilities' => $capabilities,
            'tier' => $tier,
            'last_seen_at' => now(),
        ]);

        // A device removed earlier comes back when it checks in again.
        $device->deleted_at = null;
        $device->save();

        return $device;
    }
}
