<?php

namespace App\Policies;

use App\Models\User;
use App\Models\UserDevice;

/**
 * Users can only change their own devices. (Listing is scoped to the user.)
 */
class UserDevicePolicy
{
    public function update(User $user, UserDevice $device): bool
    {
        return $device->user_id === $user->id;
    }

    public function delete(User $user, UserDevice $device): bool
    {
        return $device->user_id === $user->id;
    }
}
