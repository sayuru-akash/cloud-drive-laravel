<?php

namespace App\Enums;

enum FileStatus: string
{
    case Pending = 'pending';
    case Ready = 'ready';
    case Failed = 'failed';
    case Deleted = 'deleted';
}
