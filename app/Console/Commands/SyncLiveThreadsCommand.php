<?php

namespace App\Console\Commands;

use App\Support\Live\LiveThreadService;
use Illuminate\Console\Command;

class SyncLiveThreadsCommand extends Command
{
    protected $signature = 'noccaro:sync-live-threads';

    protected $description = 'Synchronize scheduled live threads and auto-close expired live sessions.';

    public function handle(LiveThreadService $liveThreads): int
    {
        $result = $liveThreads->synchronizeDueSchedules();

        $this->info(sprintf(
            'checked=%d started=%d closed=%d',
            $result['checked'],
            $result['started'],
            $result['closed'],
        ));

        return self::SUCCESS;
    }
}
