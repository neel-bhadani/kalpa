<?php

namespace App\Enums;

enum WorkspaceRole: string
{
    case Owner = 'owner';
    case Editor = 'editor';
    case Viewer = 'viewer';

    /**
     * Viewers only read; editors and owners can also write.
     */
    public function canWrite(): bool
    {
        return $this !== self::Viewer;
    }
}
