<?php

namespace App\Enums;

enum AssetRelation: string
{
    case Uses = 'uses';
    case VoiceOf = 'voice_of';
    case Wears = 'wears';
    case AppearsIn = 'appears_in';
    case DerivedFrom = 'derived_from';
    case ScoredBy = 'scored_by';
}
