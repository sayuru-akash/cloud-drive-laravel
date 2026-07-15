<?php

namespace App\Services;

use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;

class ObjectStorageService
{
    public function client(): S3Client
    {
        return new S3Client([
            'version' => 'latest',
            'region' => (string) config('drive.storage.region', 'auto'),
            'endpoint' => (string) config('drive.storage.endpoint'),
            'use_path_style_endpoint' => (bool) config('drive.storage.path_style', true),
            'credentials' => [
                'key' => (string) config('drive.storage.key_id'),
                'secret' => (string) config('drive.storage.secret_key'),
            ],
        ]);
    }

    public function bucket(): string
    {
        return (string) config('drive.storage.bucket');
    }

    public function isConfigured(): bool
    {
        return filled(config('drive.storage.endpoint'))
            && filled(config('drive.storage.region'))
            && filled(config('drive.storage.key_id'))
            && filled(config('drive.storage.secret_key'))
            && filled(config('drive.storage.bucket'));
    }

    public function buildStorageKey(string $fileId, int $versionNumber, string $filename): string
    {
        $safe = strtolower(trim($filename));
        $safe = preg_replace('/[^a-z0-9._-]+/', '-', $safe) ?: '';
        $safe = trim($safe, '-');

        return "workspace/default/files/{$fileId}/versions/{$versionNumber}/".($safe ?: 'file');
    }

    public function createUploadUrl(string $storageKey, string $contentType): string
    {
        $command = $this->client()->getCommand('PutObject', [
            'Bucket' => $this->bucket(),
            'Key' => $storageKey,
            'ContentType' => $contentType,
        ]);

        return (string) $this->client()->createPresignedRequest($command, '+10 minutes')->getUri();
    }

    /** @return array{uploadId:string} */
    public function createMultipartUpload(string $storageKey, string $contentType): array
    {
        $result = $this->client()->createMultipartUpload([
            'Bucket' => $this->bucket(),
            'Key' => $storageKey,
            'ContentType' => $contentType,
        ]);

        return ['uploadId' => (string) $result->get('UploadId')];
    }

    public function createMultipartPartUploadUrl(string $storageKey, string $uploadId, int $partNumber): string
    {
        $command = $this->client()->getCommand('UploadPart', [
            'Bucket' => $this->bucket(),
            'Key' => $storageKey,
            'UploadId' => $uploadId,
            'PartNumber' => $partNumber,
        ]);

        return (string) $this->client()->createPresignedRequest($command, '+60 minutes')->getUri();
    }

    /** @param array<int, array{partNumber:int, etag:string}> $parts */
    public function completeMultipartUpload(string $storageKey, string $uploadId, array $parts): void
    {
        usort($parts, fn (array $a, array $b): int => $a['partNumber'] <=> $b['partNumber']);

        try {
            $this->client()->completeMultipartUpload([
                'Bucket' => $this->bucket(),
                'Key' => $storageKey,
                'UploadId' => $uploadId,
                'MultipartUpload' => [
                    'Parts' => array_map(fn (array $part): array => [
                        'ETag' => $part['etag'],
                        'PartNumber' => $part['partNumber'],
                    ], $parts),
                ],
            ]);
        } catch (S3Exception $exception) {
            if ($exception->getAwsErrorCode() !== 'NoSuchUpload' || ! $this->completedObjectExists($storageKey)) {
                throw $exception;
            }
        }
    }

    private function completedObjectExists(string $storageKey): bool
    {
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            if ($this->objectExists($storageKey)) {
                return true;
            }

            if ($attempt < 3) {
                usleep(250_000);
            }
        }

        return false;
    }

    public function abortMultipartUpload(string $storageKey, string $uploadId): void
    {
        $this->client()->abortMultipartUpload([
            'Bucket' => $this->bucket(),
            'Key' => $storageKey,
            'UploadId' => $uploadId,
        ]);
    }

    public function objectExists(string $storageKey): bool
    {
        return $this->objectMetadata($storageKey) !== null;
    }

    /** @return array{contentLength:int, contentType:?string, etag:?string}|null */
    public function objectMetadata(string $storageKey): ?array
    {
        try {
            $result = $this->client()->headObject(['Bucket' => $this->bucket(), 'Key' => $storageKey]);

            return [
                'contentLength' => (int) $result->get('ContentLength'),
                'contentType' => $result->get('ContentType') ? (string) $result->get('ContentType') : null,
                'etag' => $result->get('ETag') ? (string) $result->get('ETag') : null,
            ];
        } catch (\Throwable) {
            return null;
        }
    }

    public function deleteObject(string $storageKey): void
    {
        $this->client()->deleteObject(['Bucket' => $this->bucket(), 'Key' => $storageKey]);
    }

    public function createDownloadUrl(string $storageKey, ?string $filename = null): string
    {
        $command = $this->client()->getCommand('GetObject', [
            'Bucket' => $this->bucket(),
            'Key' => $storageKey,
            'ResponseContentDisposition' => $this->downloadDisposition($filename),
        ]);

        return (string) $this->client()->createPresignedRequest($command, '+5 minutes')->getUri();
    }

    public function writeObjectToPath(string $storageKey, string $destination): void
    {
        $this->client()->getObject([
            'Bucket' => $this->bucket(),
            'Key' => $storageKey,
            'SaveAs' => $destination,
        ]);
    }

    public function downloadDisposition(?string $filename): string
    {
        if (! $filename) {
            return 'attachment; filename="file"';
        }

        $safeFilename = trim(str_replace(["\r", "\n", '"'], '', $filename));
        $fallback = preg_replace('/[^a-zA-Z0-9._-]/', '_', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $safeFilename) ?: '') ?: 'file';
        $fallback = trim($fallback, '_') ?: 'file';
        $encoded = rawurlencode($safeFilename ?: 'file');

        return "attachment; filename=\"{$fallback}\"; filename*=UTF-8''{$encoded}";
    }
}
