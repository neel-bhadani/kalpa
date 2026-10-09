<?php

namespace App\Enums;

enum TaskMode: string
{
    case Auto = 'auto';
    case Semi = 'semi';
    case Manual = 'manual';
}
