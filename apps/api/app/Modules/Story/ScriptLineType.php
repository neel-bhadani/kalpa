<?php

namespace App\Modules\Story;

/**
 * Screenplay elements a script line can be (scene headings are the scenes themselves).
 * Same list as packages/schemas/script.schema.json.
 */
enum ScriptLineType: string
{
    case Action = 'action';
    case Character = 'character';
    case Dialogue = 'dialogue';
    case Parenthetical = 'parenthetical';
    case Transition = 'transition';
    case Narration = 'narration';
}
