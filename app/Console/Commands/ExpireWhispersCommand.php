<?php

namespace App\Console\Commands;

use App\Support\Whispers\WhisperLifecycleService;
use Illuminate\Console\Command;

class ExpireWhispersCommand extends Command
{
    protected $signature = 'noccaro:expire-whispers';

    protected $description = 'Expire overdue whispers and purge their images';

    public function handle(WhisperLifecycleService $lifecycle): int
    {
        $expiredCount = $lifecycle->expireOverdueWhispers();

        $this->info(sprintf('Expired %d whispers.', $expiredCount));

        return self::SUCCESS;
    }
}
