<?php

namespace App\Enums;

/**
 * What a registered model does.
 */
enum ModelCapability: string
{
    case Llm = 'llm';
    case Tts = 'tts';
    case VoiceClone = 'voice_clone';
    case Music = 'music';
    case Upscale = 'upscale';
    case Image = 'image';
    case Asset3d = 'asset3d';
    case Stt = 'stt';
    case Lipsync = 'lipsync';
    case Segment = 'segment';
    case Depth = 'depth';
    case Track = 'track';
}
