<?php

namespace App\Notifications;

use App\Models\Upload;
use Illuminate\Notifications\Notification;

/**
 * The stored bytes didn't match the SHA-256 the upload announced, so the file was thrown away.
 */
class UploadRejected extends Notification
{
    public function __construct(private Upload $upload) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'upload_rejected',
            'title' => 'Upload rejected',
            'body' => "\"{$this->upload->file_name}\" didn't match its checksum and was removed. Please upload it again.",
            'upload_id' => $this->upload->id,
        ];
    }
}
