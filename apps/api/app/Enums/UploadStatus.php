<?php

namespace App\Enums;

enum UploadStatus: string
{
    case Pending = 'pending';
    case Completed = 'completed';
    case Aborted = 'aborted';
    case Expired = 'expired';
}
