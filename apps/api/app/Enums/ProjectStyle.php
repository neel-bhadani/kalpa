<?php

namespace App\Enums;

enum ProjectStyle: string
{
    case TwoD = '2d';
    case ThreeD = '3d';
    case Realistic = 'realistic';
    case Mixed = 'mixed';
}
