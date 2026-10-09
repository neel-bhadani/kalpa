<?php

namespace App\Actions;

use App\Core\Storage\ObjectStorage;
use App\Models\Upload;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class PresignUploadParts
{
    public function __construct(private ObjectStorage $storage) {}

    /**
     * Presigned PUT URLs for the given part numbers.
     *
     * @param  list<int>  $partNumbers
     * @return array{urls: list<array{number: int, url: string}>, expires_at: Carbon}
     */
    public function handle(Upload $upload, array $partNumbers): array
    {
        if (! $upload->isOpen()) {
            throw ValidationException::withMessages(['upload' => "This upload is {$upload->status->value} and takes no more parts."]);
        }

        foreach ($partNumbers as $number) {
            if ($number < 1 || $number > $upload->partCount()) {
                throw ValidationException::withMessages(['part_numbers' => "Part numbers go from 1 to {$upload->partCount()}."]);
            }
        }

        $expires = now()->addMinutes(config('uploads.part_url_minutes'));

        return [
            'urls' => array_map(fn (int $number) => [
                'number' => $number,
                'url' => $this->storage->presignPart($upload->path, $upload->s3_upload_id, $number, $expires),
            ], $partNumbers),
            'expires_at' => $expires,
        ];
    }
}
