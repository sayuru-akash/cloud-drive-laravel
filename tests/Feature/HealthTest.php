<?php

it('reports storage readiness only when all required object storage settings are present', function (): void {
    config([
        'drive.storage.endpoint' => 'https://s3.example.com',
        'drive.storage.region' => 'us-west-004',
        'drive.storage.key_id' => 'key',
        'drive.storage.secret_key' => 'secret',
        'drive.storage.bucket' => 'bucket',
    ]);

    $this->getJson(route('health'))
        ->assertOk()
        ->assertJsonPath('ready.storageConfigured', true);

    config(['drive.storage.bucket' => '']);

    $this->getJson(route('health'))
        ->assertOk()
        ->assertJsonPath('ready.storageConfigured', false);
});
