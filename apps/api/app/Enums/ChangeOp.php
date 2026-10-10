<?php

namespace App\Enums;

enum ChangeOp: string
{
    case Upsert = 'upsert';
    case Delete = 'delete';
}
