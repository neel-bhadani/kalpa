<?php

namespace App\Enums;

enum DerivativeKind: string
{
    case Proxy360 = 'proxy_360';
    case Proxy540 = 'proxy_540';
    case Thumb = 'thumb';
    case Waveform = 'waveform';
    case Lod1 = 'lod1';
    case Lod2 = 'lod2';
    case Ktx2 = 'ktx2';
}
