<?php

namespace App\Enums;

enum UploadStatus: string
{
    case Initiated = 'initiated';
    case Uploading = 'uploading';
    case Completed = 'completed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
}
