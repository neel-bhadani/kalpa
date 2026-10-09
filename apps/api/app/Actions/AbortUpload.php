<?php

namespace App\Actions;

use App\Core\Storage\ObjectStorage;
use App\Enums\UploadStatus;
use App\Models\Upload;
use Illuminate\Validation\ValidationException;

class AbortUpload
{
    public function __construct(private ObjectStorage $storage) {}

    /**
     * Stop an upload and drop its stored parts.
     *
     * @param  UploadStatus  $as  Aborted (by the user) or Expired (by the sweep)
     */
    public function handle(Upload $upload, UploadStatus $as = UploadStatus::Aborted): void
    {
        if ($upload->status === UploadStatus::Completed) {
            throw ValidationException::withMessages(['upload' => 'This upload is already complete.']);
        }

        $this->storage->abortMultipart($upload->path, $upload->s3_upload_id);

        if ($upload->status === UploadStatus::Pending) {
            $upload->update(['status' => $as]);
        }
    }
}
