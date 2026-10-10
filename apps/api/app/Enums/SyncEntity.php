<?php

namespace App\Enums;

/**
 * The kinds of rows a device keeps in its offline cache and pulls through GET /sync.
 */
enum SyncEntity: string
{
    case Project = 'project';
    case Task = 'task';
    case Asset = 'asset';
    case AssetVersion = 'asset_version';
    case ProjectMemory = 'project_memory';
    case ProjectAsset = 'project_asset';
    case TaskOutput = 'task_output';
}
