<?php

namespace App\Enums;

enum TaskEventType: string
{
    case Created = 'created';
    case Updated = 'updated';
    case StatusChanged = 'status_changed';
    case CheckpointSaved = 'checkpoint_saved';
    case DependencyAdded = 'dependency_added';
    case DependencyRemoved = 'dependency_removed';
    case Deleted = 'deleted';
}
