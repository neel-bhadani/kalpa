<?php

namespace App\Core\Jobs\Handlers;

use App\Core\Jobs\ServerJobHandler;
use App\Core\Storage\ObjectStorage;
use App\Enums\DerivativeKind;
use App\Models\File;
use App\Models\FileDerivative;
use App\Models\Job;
use RuntimeException;

/**
 * file.thumbnail: a WebP no larger than 512 px on its long side, made with GD, stored as a
 * content-addressed file and recorded in file_derivatives (kind "thumb"). Input: { file_id }.
 */
class MakeThumbnail implements ServerJobHandler
{
    public function __construct(private ObjectStorage $storage) {}

    public function handle(Job $job): array
    {
        $file = File::findOrFail($job->input->file_id);

        if ($existing = FileDerivative::where('source_file_id', $file->id)->where('kind', DerivativeKind::Thumb)->first()) {
            return ['derivative_id' => $existing->id, 'skipped' => 'exists'];
        }

        if (! str_starts_with($file->mime, 'image/')) {
            return ['skipped' => 'not an image'];
        }

        // GD decodes the whole image into memory, so very large ones are left without a thumbnail.
        if ($file->size_bytes > config('jobs.thumbnail_max_bytes')) {
            return ['skipped' => 'file too large'];
        }

        $bytes = $this->storage->get($file->path);
        $info = getimagesizefromstring($bytes) ?: throw new RuntimeException('Not an image GD can read.');
        [$width, $height] = $info;

        if ($width * $height > config('jobs.thumbnail_max_pixels')) {
            return ['skipped' => 'too many pixels', 'width' => $width, 'height' => $height];
        }

        $webp = $this->resize($bytes, $width, $height, config('jobs.thumbnail_size'), $thumbWidth, $thumbHeight);
        $sha = hash('sha256', $webp);
        $path = File::pathFor($file->workspace_id, $sha);
        $this->storage->put($path, $webp, 'image/webp');

        $thumb = File::firstOrCreate(
            ['workspace_id' => $file->workspace_id, 'sha256' => $sha],
            ['size_bytes' => strlen($webp), 'mime' => 'image/webp', 'storage_disk' => 's3', 'path' => $path,
                'width' => $thumbWidth, 'height' => $thumbHeight, 'created_by' => $file->created_by],
        );
        // Made here from verified bytes, so already verified.
        $thumb->forceFill(['verified_at' => $thumb->verified_at ?? now()])->save();

        // We now know the source's size too.
        $file->forceFill(['width' => $file->width ?? $width, 'height' => $file->height ?? $height])->save();

        $derivative = FileDerivative::firstOrCreate(
            ['source_file_id' => $file->id, 'kind' => DerivativeKind::Thumb],
            ['workspace_id' => $file->workspace_id, 'file_id' => $thumb->id,
                'params' => ['format' => 'webp', 'width' => $thumbWidth, 'height' => $thumbHeight]],
        );

        return ['derivative_id' => $derivative->id, 'file_id' => $thumb->id, 'width' => $thumbWidth, 'height' => $thumbHeight];
    }

    /**
     * Scale down to fit $max (never up) and encode as WebP.
     */
    private function resize(string $bytes, int $width, int $height, int $max, ?int &$newWidth, ?int &$newHeight): string
    {
        $source = imagecreatefromstring($bytes) ?: throw new RuntimeException('GD could not decode the image.');

        $scale = min(1, $max / max($width, $height));
        $newWidth = max(1, (int) round($width * $scale));
        $newHeight = max(1, (int) round($height * $scale));

        $thumb = imagescale($source, $newWidth, $newHeight, IMG_BICUBIC);
        imagedestroy($source);

        // Keep transparency from PNGs and GIFs.
        imagealphablending($thumb, false);
        imagesavealpha($thumb, true);

        ob_start();
        imagewebp($thumb, null, 80);
        imagedestroy($thumb);

        return (string) ob_get_clean();
    }
}
