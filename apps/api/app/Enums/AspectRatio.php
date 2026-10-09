<?php

namespace App\Enums;

enum AspectRatio: string
{
    case Wide = '16:9';
    case Tall = '9:16';
    case Square = '1:1';
    case Portrait = '4:5';
    case Classic = '4:3';
}
