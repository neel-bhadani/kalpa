<?php

namespace App\Enums;

enum UploadStatus: string
{
    case Pending = 'pending';
    case Completed = 'completed';
    case Aborted = 'aborted';
    case Expired = 'expired';
    // The stored bytes did not match the announced SHA-256.
    case Failed = 'failed';
}
