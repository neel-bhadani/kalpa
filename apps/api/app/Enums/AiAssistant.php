<?php

namespace App\Enums;

/**
 * The nine assistants: one per creative module, plus the Producer that coordinates them.
 */
enum AiAssistant: string
{
    case Producer = 'producer';
    case Story = 'story';
    case Character = 'character';
    case Assets3d = 'assets3d';
    case Cgi = 'cgi';
    case Voice = 'voice';
    case Music = 'music';
    case Video = 'video';
    case Ads = 'ads';
}
