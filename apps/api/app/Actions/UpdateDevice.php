<?php

namespace App\Actions;

use App\Models\UserDevice;

class UpdateDevice
{
    /**
     * Rename the device or set its tier override (null goes back to the detected tier).
     *
     * @param  array{name?: string, tier_override?: string|null}  $changes
     */
    public function handle(UserDevice $device, array $changes): UserDevice
    {
        $device->fill($changes)->save();

        return $device;
    }
}
