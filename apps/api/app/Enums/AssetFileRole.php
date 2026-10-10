<?php

namespace App\Enums;

enum AssetFileRole: string
{
    case Model = 'model';
    case Texture = 'texture';
    case Audio = 'audio';
    case Video = 'video';
    case Image = 'image';
    case Thumbnail = 'thumbnail';
    case Preview = 'preview';
    case Lipsync = 'lipsync';
    case Stem = 'stem';
    case Source = 'source';
}
