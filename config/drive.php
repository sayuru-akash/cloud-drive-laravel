<?php

return [
    'max_upload_size_bytes' => (int) env('MAX_UPLOAD_SIZE_BYTES', 10 * 1024 * 1024 * 1024),
    'soft_delete_retention_days' => (int) env('DEFAULT_SOFT_DELETE_RETENTION_DAYS', 30),
    'default_share_expiry_days' => (int) env('DEFAULT_SHARE_EXPIRY_DAYS', 7),
    'internal_email_domain' => env('INTERNAL_EMAIL_DOMAIN'),
    'multipart_threshold_bytes' => (int) env('B2_MULTIPART_THRESHOLD_BYTES', 100 * 1024 * 1024),
    'multipart_chunk_size_bytes' => (int) env('B2_MULTIPART_CHUNK_SIZE_BYTES', 32 * 1024 * 1024),
    'parallel_file_uploads' => (int) env('PARALLEL_FILE_UPLOADS', 2),
    'parallel_part_uploads' => (int) env('PARALLEL_PART_UPLOADS', 4),
    'blocked_extensions' => [
        'bat', 'cmd', 'com', 'cpl', 'dll', 'exe', 'hta', 'jar', 'js', 'jse',
        'lnk', 'msi', 'msp', 'pif', 'ps1', 'reg', 'scr', 'sh', 'sys', 'vb',
        'vbe', 'wsf',
    ],
    'storage' => [
        'provider' => env('OBJECT_STORAGE_PROVIDER', 'b2'),
        'endpoint' => env('B2_S3_ENDPOINT'),
        'region' => env('B2_REGION', env('AWS_DEFAULT_REGION', 'us-west-004')),
        'key_id' => env('B2_KEY_ID'),
        'secret_key' => env('B2_APPLICATION_KEY'),
        'bucket' => env('B2_BUCKET_NAME'),
        'path_style' => (bool) env('B2_USE_PATH_STYLE_ENDPOINT', true),
    ],
];
