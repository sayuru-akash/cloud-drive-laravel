<?php

use App\Services\ObjectStorageService;

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
