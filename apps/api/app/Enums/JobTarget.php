<?php

namespace App\Enums;

/**
 * Where a job runs: the user's device (a browser tab), one of their workers, or the server queue.
 */
enum JobTarget: string
{
    case Device = 'device';
    case Worker = 'worker';
    case Server = 'server';
}
