<?php

namespace App\Enums;

/**
 * Where a compute worker runs: a browser tab, the user's local worker, the desktop app, or a shared pool.
 */
enum WorkerKind: string
{
    case Browser = 'browser';
    case Local = 'local';
    case Desktop = 'desktop';
    case Pool = 'pool';
}
