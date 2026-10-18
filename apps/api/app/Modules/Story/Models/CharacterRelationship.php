<?php

namespace App\Modules\Story\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * How two characters of a project's story relate. a_id and b_id are character ids in the story spec.
 */
#[Fillable(['workspace_id', 'project_id', 'a_id', 'b_id', 'relation', 'notes'])]
class CharacterRelationship extends Model
{
    use HasUuids, SoftDeletes;
}
