<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['workspace_id', 'task_id', 'depends_on_task_id'])]
class TaskDependency extends Model
{
    use HasUuids;
}
