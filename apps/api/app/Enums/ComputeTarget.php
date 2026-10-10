<?php

namespace App\Enums;

/**
 * Where a model or an assistant runs: this device, the user's worker, the server, or the user's own API provider.
 */
enum ComputeTarget: string
{
    case Device = 'device';
    case Worker = 'worker';
    case Server = 'server';
    case Provider = 'provider';
}
