<?php

use App\Exceptions\DownloadUnavailableException;
use App\Services\ObjectStorageService;
use Aws\Command;
use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;
use GuzzleHttp\Psr7\Response;

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

it('maps a Backblaze download cap response to a safe domain exception', function (): void {
    $providerException = new S3Exception(
        'Cannot download file, download bandwidth or transaction (Class B) cap exceeded.',
        new Command('HeadObject'),
        [
            'code' => 'AccessDenied',
            'message' => 'Cannot download file, download bandwidth or transaction (Class B) cap exceeded.',
            'response' => new Response(403),
        ],
    );
    $client = Mockery::mock(S3Client::class);
    $client->shouldReceive('headObject')
        ->once()
        ->with(['Bucket' => 'test-bucket', 'Key' => 'objects/large-video.mp4'])
        ->andThrow($providerException);

    $service = new class($client) extends ObjectStorageService
    {
        public function __construct(private readonly S3Client $storageClient) {}

        public function client(): S3Client
        {
            return $this->storageClient;
        }

        public function bucket(): string
        {
            return 'test-bucket';
        }
    };

    try {
        $service->ensureDownloadAvailable('objects/large-video.mp4');
        test()->fail('Expected the download availability check to fail.');
    } catch (DownloadUnavailableException $exception) {
        expect($exception->userMessage())
            ->toBe('Downloads are temporarily unavailable because the storage download limit has been reached. Please try again later or contact the link owner.')
            ->and($exception->getPrevious())->toBe($providerException);
    }
});

it('maps a bodyless Backblaze head forbidden response to the download cap warning', function (): void {
    $providerException = new S3Exception(
        'Forbidden.',
        new Command('HeadObject'),
        ['response' => new Response(403)],
    );
    $client = Mockery::mock(S3Client::class);
    $client->shouldReceive('headObject')->once()->andThrow($providerException);

    $service = new class($client) extends ObjectStorageService
    {
        public function __construct(private readonly S3Client $storageClient) {}

        public function client(): S3Client
        {
            return $this->storageClient;
        }

        public function bucket(): string
        {
            return 'test-bucket';
        }

        protected function endpoint(): string
        {
            return 'https://s3.us-west-004.backblazeb2.com';
        }
    };

    try {
        $service->ensureDownloadAvailable('objects/video.mp4');
        test()->fail('Expected the bodyless Backblaze response to block the download.');
    } catch (DownloadUnavailableException $exception) {
        expect($exception->getMessage())
            ->toBe('The storage provider could not serve this download.')
            ->and($exception->userMessage())
            ->toContain('storage download limit has been reached');
    }
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
