<?php

namespace App\Core\Storage;

use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;
use DateTimeInterface;

/**
 * The S3 bucket (SeaweedFS locally). Server calls go to the internal endpoint;
 * presigned URLs are signed for the public endpoint the browser uses.
 */
class ObjectStorage
{
    public function __construct(
        private S3Client $client,
        private S3Client $publicClient,
        private string $bucket,
    ) {}

    /**
     * @param  array<string, mixed>  $config  the "s3" disk config
     */
    public static function fromConfig(array $config): self
    {
        $options = [
            'version' => 'latest',
            'region' => $config['region'],
            'credentials' => ['key' => $config['key'], 'secret' => $config['secret']],
            'use_path_style_endpoint' => (bool) $config['use_path_style_endpoint'],
            'request_checksum_calculation' => $config['request_checksum_calculation'] ?? 'when_required',
            'response_checksum_validation' => $config['response_checksum_validation'] ?? 'when_required',
        ];

        return new self(
            new S3Client([...$options, 'endpoint' => $config['endpoint']]),
            new S3Client([...$options, 'endpoint' => $config['public_endpoint']]),
            $config['bucket'],
        );
    }

    public function bucket(): string
    {
        return $this->bucket;
    }

    /**
     * Create the bucket if it doesn't exist yet.
     */
    public function ensureBucket(string $bucket): bool
    {
        if ($this->client->doesBucketExistV2($bucket, false)) {
            return false;
        }

        $this->client->createBucket(['Bucket' => $bucket]);

        return true;
    }

    public function startMultipart(string $path, string $mime): string
    {
        return $this->client->createMultipartUpload([
            'Bucket' => $this->bucket,
            'Key' => $path,
            'ContentType' => $mime,
        ])['UploadId'];
    }

    public function presignPart(string $path, string $uploadId, int $partNumber, DateTimeInterface $expires): string
    {
        $command = $this->publicClient->getCommand('UploadPart', [
            'Bucket' => $this->bucket,
            'Key' => $path,
            'UploadId' => $uploadId,
            'PartNumber' => $partNumber,
        ]);

        return (string) $this->publicClient->createPresignedRequest($command, $expires)->getUri();
    }

    /**
     * Parts stored so far, by part number.
     *
     * @return array<int, array{number: int, etag: string, size: int}>
     */
    public function listParts(string $path, string $uploadId): array
    {
        $parts = [];
        $marker = 0;

        do {
            $page = $this->client->listParts([
                'Bucket' => $this->bucket,
                'Key' => $path,
                'UploadId' => $uploadId,
                'PartNumberMarker' => $marker,
            ]);

            foreach ($page['Parts'] ?? [] as $part) {
                $parts[(int) $part['PartNumber']] = [
                    'number' => (int) $part['PartNumber'],
                    'etag' => trim($part['ETag'], '"'),
                    'size' => (int) $part['Size'],
                ];
            }

            $marker = (int) ($page['NextPartNumberMarker'] ?? 0);
        } while ($page['IsTruncated'] ?? false);

        ksort($parts);

        return $parts;
    }

    /**
     * @param  list<array{number: int, etag: string}>  $parts
     */
    public function completeMultipart(string $path, string $uploadId, array $parts): void
    {
        $this->client->completeMultipartUpload([
            'Bucket' => $this->bucket,
            'Key' => $path,
            'UploadId' => $uploadId,
            'MultipartUpload' => [
                'Parts' => array_map(fn (array $part) => ['PartNumber' => $part['number'], 'ETag' => '"'.$part['etag'].'"'], $parts),
            ],
        ]);
    }

    /**
     * Abort and drop the stored parts. Already gone is fine.
     */
    public function abortMultipart(string $path, string $uploadId): void
    {
        try {
            $this->client->abortMultipartUpload(['Bucket' => $this->bucket, 'Key' => $path, 'UploadId' => $uploadId]);
        } catch (S3Exception $e) {
            if ($e->getAwsErrorCode() !== 'NoSuchUpload') {
                throw $e;
            }
        }
    }

    public function multipartExists(string $path, string $uploadId): bool
    {
        try {
            $this->listParts($path, $uploadId);

            return true;
        } catch (S3Exception $e) {
            if ($e->getAwsErrorCode() === 'NoSuchUpload' || $e->getStatusCode() === 404) {
                return false;
            }
            throw $e;
        }
    }

    public function size(string $path): ?int
    {
        try {
            return (int) $this->client->headObject(['Bucket' => $this->bucket, 'Key' => $path])['ContentLength'];
        } catch (S3Exception $e) {
            if ($e->getStatusCode() === 404) {
                return null;
            }
            throw $e;
        }
    }

    public function delete(string $path): void
    {
        $this->client->deleteObject(['Bucket' => $this->bucket, 'Key' => $path]);
    }

    public function presignGet(string $path, string $mime, DateTimeInterface $expires): string
    {
        $command = $this->publicClient->getCommand('GetObject', [
            'Bucket' => $this->bucket,
            'Key' => $path,
            'ResponseContentType' => $mime,
        ]);

        return (string) $this->publicClient->createPresignedRequest($command, $expires)->getUri();
    }
}
