<?php

namespace App\Core\Sync;

use App\Models\UserDevice;

/**
 * The device making this request (from the X-Device-Id header or a sync push), if known.
 * One instance per request; stamped on change_log rows.
 */
class CurrentDevice
{
    private ?UserDevice $device = null;

    public function set(?UserDevice $device): void
    {
        $this->device = $device;
    }

    public function get(): ?UserDevice
    {
        return $this->device;
    }

    public function id(): ?string
    {
        return $this->device?->id;
    }
}
