<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

final class DownloadUnavailableException extends RuntimeException
{
    private const CAPACITY = 'capacity';

    private const MISSING = 'missing';

    private const TEMPORARY = 'temporary';

    private function __construct(private readonly string $reason, Throwable $previous)
    {
        parent::__construct('The storage provider could not serve this download.', previous: $previous);
    }

    public static function capacityExceeded(Throwable $previous): self
    {
        return new self(self::CAPACITY, $previous);
    }

    public static function missing(Throwable $previous): self
    {
        return new self(self::MISSING, $previous);
    }

    public static function temporary(Throwable $previous): self
    {
        return new self(self::TEMPORARY, $previous);
    }

    public function userMessage(): string
    {
        return match ($this->reason) {
            self::CAPACITY => 'Downloads are temporarily unavailable because the storage download limit has been reached. Please try again later or contact the link owner.',
            self::MISSING => 'This file is no longer available in storage.',
            default => 'The storage service could not prepare this download. Please try again shortly.',
        };
    }
}
