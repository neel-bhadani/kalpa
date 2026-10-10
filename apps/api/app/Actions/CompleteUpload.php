<?php

namespace App\Actions;

use App\Core\Storage\ObjectStorage;
use App\Enums\UploadStatus;
use App\Models\File;
use App\Models\Upload;
use Aws\S3\Exception\S3Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CompleteUpload
{
    public function __construct(private ObjectStorage $storage) {}

    /**
     * Join the parts into one object and record the file. The hash is checked later (P0 step 7).
     * A temporary upload (see CreateUpload) returns null: its file is linked once the hash is verified.
     *
     * @param  list<array{number: int, etag: string}>  $parts
     *
     * @throws ValidationException when parts are missing or the size is wrong
     */
    public function handle(Upload $upload, array $parts): ?File
    {
        return DB::transaction(function () use ($upload, $parts) {
            $upload = Upload::whereKey($upload->id)->lockForUpdate()->firstOrFail();

            // Completing twice returns the same file.
            if ($upload->status === UploadStatus::Completed) {
                return $upload->file;
            }

            if (! $upload->isOpen()) {
                $this->fail('upload', "This upload is {$upload->status->value}.");
            }

            $stored = $this->storage->listParts($upload->path, $upload->s3_upload_id);
            $this->checkParts($upload, $parts, $stored);

            try {
                $this->storage->completeMultipart($upload->path, $upload->s3_upload_id, array_values(array_map(
                    fn (array $part) => ['number' => $part['number'], 'etag' => $part['etag']],
                    $stored,
                )));
            } catch (S3Exception $e) {
                // E.g. EntityTooSmall: every part but the last must be at least 5 MB.
                $this->fail('parts', "Storage refused the parts: {$e->getAwsErrorMessage()}");
            }

            if ($this->storage->size($upload->path) !== $upload->size_bytes) {
                $this->fail('size_bytes', 'The stored file is not the size that was announced.');
            }

            if ($upload->isTemporary()) {
                $upload->update(['status' => UploadStatus::Completed]);

                return null;
            }

            $file = File::firstOrCreate(
                ['workspace_id' => $upload->workspace_id, 'sha256' => $upload->sha256],
                [
                    'size_bytes' => $upload->size_bytes,
                    'mime' => $upload->mime,
                    'storage_disk' => 's3',
                    'path' => $upload->path,
                    'created_by' => $upload->user_id,
                ],
            );

            $upload->update(['status' => UploadStatus::Completed, 'file_id' => $file->id]);

            return $file;
        });
    }

    /**
     * Every part 1..n must be stored, match what the client sent, and add up to the announced size.
     *
     * @param  list<array{number: int, etag: string}>  $parts
     * @param  array<int, array{number: int, etag: string, size: int}>  $stored
     */
    private function checkParts(Upload $upload, array $parts, array $stored): void
    {
        $expected = range(1, $upload->partCount());

        if (array_keys($stored) !== $expected || count($parts) !== count($expected)) {
            $this->fail('parts', 'Some parts are missing. Upload all parts before completing.');
        }

        foreach ($parts as $part) {
            $etag = trim($part['etag'], '"');
            if (($stored[$part['number']]['etag'] ?? null) !== $etag) {
                $this->fail('parts', "Part {$part['number']} does not match what was stored.");
            }
        }

        if (array_sum(array_column($stored, 'size')) !== $upload->size_bytes) {
            $this->fail('size_bytes', 'The uploaded parts do not add up to the announced file size.');
        }
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
