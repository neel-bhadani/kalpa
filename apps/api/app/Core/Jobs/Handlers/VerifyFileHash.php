<?php

namespace App\Core\Jobs\Handlers;

use App\Actions\CreateJob;
use App\Core\Jobs\ServerJobHandler;
use App\Core\Storage\ObjectStorage;
use App\Enums\JobPriority;
use App\Enums\UploadStatus;
use App\Models\AssetFile;
use App\Models\File;
use App\Models\Job;
use App\Models\Upload;
use App\Notifications\UploadRejected;
use Illuminate\Support\Facades\DB;

/**
 * file.verify_hash: re-hash an upload's stored bytes and compare with the SHA-256 the browser announced.
 * Input: { upload_id }.
 */
class VerifyFileHash implements ServerJobHandler
{
    public function __construct(
        private ObjectStorage $storage,
        private CreateJob $createJob,
    ) {}

    public function handle(Job $job): array
    {
        $upload = Upload::findOrFail($job->input->upload_id);
        $actual = $this->storage->sha256($upload->path);

        if ($actual !== $upload->sha256) {
            $this->reject($upload);

            return ['match' => false, 'expected' => $upload->sha256, 'actual' => $actual];
        }

        $file = $this->accept($upload);

        if (str_starts_with($file->mime, 'image/')) {
            $this->createJob->handle(
                $file->workspace_id,
                $upload->user,
                'file.thumbnail',
                input: ['file_id' => $file->id],
                priority: JobPriority::Background,
                idempotencyKey: "file.thumbnail:{$file->id}",
            );
        }

        return ['match' => true, 'file_id' => $file->id];
    }

    /**
     * The bytes are proven. A temporary upload replaces whatever sits at the final path
     * (it may be an unverified copy from someone else) and is linked to the workspace's file.
     */
    private function accept(Upload $upload): File
    {
        if (! $upload->isTemporary()) {
            $file = $upload->file;
            $file->forceFill(['verified_at' => now()])->save();

            return $file;
        }

        $finalPath = File::pathFor($upload->workspace_id, $upload->sha256);
        $this->storage->copy($upload->path, $finalPath);
        $this->storage->delete($upload->path);

        return DB::transaction(function () use ($upload, $finalPath) {
            $file = File::firstOrCreate(
                ['workspace_id' => $upload->workspace_id, 'sha256' => $upload->sha256],
                ['size_bytes' => $upload->size_bytes, 'mime' => $upload->mime, 'storage_disk' => 's3', 'path' => $finalPath, 'created_by' => $upload->user_id],
            );
            $file->forceFill(['verified_at' => now()])->save();
            $upload->update(['file_id' => $file->id]);

            return $file;
        });
    }

    /**
     * Wrong bytes: remove them, mark the upload failed and tell the uploader. The files row goes too,
     * unless an asset already uses it (then it stays, unverified, so the asset doesn't break).
     */
    private function reject(Upload $upload): void
    {
        $this->storage->delete($upload->path);

        DB::transaction(function () use ($upload) {
            $file = $upload->isTemporary() ? null : $upload->file;
            $upload->update(['status' => UploadStatus::Failed]);

            if ($file !== null && ! AssetFile::where('file_id', $file->id)->exists()) {
                $file->delete();
            }
        });

        $upload->user->notify(new UploadRejected($upload));
    }
}
