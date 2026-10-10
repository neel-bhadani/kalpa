<?php

namespace App\Enums;

enum AssetOrigin: string
{
    case Created = 'created';
    case Generated = 'generated';
    case Imported = 'imported';
    case Library = 'library';
}
