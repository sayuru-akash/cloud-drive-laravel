<?php

use App\Services\ObjectStorageService;
use Aws\Command;
use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;

it('builds stable storage keys', function (): void {
    $service = new ObjectStorageService;

    expect($service->buildStorageKey('file_123', 1, 'My File (Final).PDF'))
        ->toBe('workspace/default/files/file_123/versions/1/my-file-final-.pdf');
});

it('builds safe attachment disposition headers', function (): void {
    $service = new ObjectStorageService;

    expect($service->downloadDisposition('test (v2).pdf'))
        ->toBe('attachment; filename="test__v2_.pdf"; filename*=UTF-8\'\'test%20%28v2%29.pdf');
});

it('recovers multipart completion when the provider already created the object', function (): void {
    $exception = new S3Exception(
        'The upload no longer exists.',
        new Command('CompleteMultipartUpload'),
        ['code' => 'NoSuchUpload'],
    );
    $client = Mockery::mock(S3Client::class);
    $client->shouldReceive('completeMultipartUpload')
        ->once()
        ->with(Mockery::on(fn (array $payload): bool => array_column(
            $payload['MultipartUpload']['Parts'],
            'PartNumber',
        ) === [1, 2]))
        ->andThrow($exception);

    $service = new class($client) extends ObjectStorageService
    {
        public int $existenceChecks = 0;

        public function __construct(private readonly S3Client $storageClient) {}

        public function client(): S3Client
        {
            return $this->storageClient;
        }

        public function bucket(): string
        {
            return 'test-bucket';
        }

        public function objectExists(string $storageKey): bool
        {
            $this->existenceChecks++;

            return $storageKey === 'objects/video.mp4';
        }
    };

    $service->completeMultipartUpload('objects/video.mp4', 'upload-id', [
        ['partNumber' => 2, 'etag' => 'etag-2'],
        ['partNumber' => 1, 'etag' => 'etag-1'],
    ]);

    expect($service->existenceChecks)->toBe(1);
});
