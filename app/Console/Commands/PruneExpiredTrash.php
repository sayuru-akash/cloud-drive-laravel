<?php

namespace App\Console\Commands;

use App\Services\TrashRetentionService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('drive:prune-trash')]
#[Description('Permanently delete trash that has passed the configured retention window')]
class PruneExpiredTrash extends Command
{
    public function handle(TrashRetentionService $trash): int
    {
        try {
            $stats = $trash->pruneExpired();
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            report($exception);

            return self::FAILURE;
        }

        $this->components->info("Pruned {$stats['files']} files, {$stats['folders']} folders, and {$stats['objects']} storage objects.");

        return self::SUCCESS;
    }
}
