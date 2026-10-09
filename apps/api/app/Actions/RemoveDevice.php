<?php

namespace App\Actions;

use App\Models\UserDevice;

class RemoveDevice
{
    public function handle(UserDevice $device): void
    {
        $device->delete();
    }
}
