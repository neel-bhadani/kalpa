<?php

namespace App\Core;

/**
 * Development helpers (the fake brain, demo.count, the demo buttons and the Producer demo plan)
 * exist only in the local environment, and in tests.
 */
class DevTools
{
    public static function enabled(): bool
    {
        return app()->environment('local', 'testing');
    }
}
