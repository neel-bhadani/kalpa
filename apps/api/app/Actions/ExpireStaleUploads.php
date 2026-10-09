<?php

namespace App\Actions;

use App\Enums\UploadStatus;
use App\Models\Upload;

class ExpireStaleUploads
{
    public function __construct(private AbortUpload $abortUpload) {}

    /**
     * Abort pending uploads past their expiry time. Returns how many were expired.
     */
    public function handle(): int
    {
        $count = 0;

        Upload::where('status', UploadStatus::Pending)
            ->where('expires_at', '<', now())
            ->lazyById()
            ->each(function (Upload $upload) use (&$count) {
                $this->abortUpload->handle($upload, UploadStatus::Expired);
                $count++;
            });

        return $count;
    }
}
