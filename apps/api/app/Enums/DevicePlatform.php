<?php

namespace App\Enums;

enum DevicePlatform: string
{
    case Web = 'web';
    case Desktop = 'desktop';
    case Ios = 'ios';
    case Android = 'android';
}
