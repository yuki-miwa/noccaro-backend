<?php

namespace App\Jobs;

use App\Support\Notifications\NoticePushService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

class SendSpaceNotificationJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public readonly int $notificationId) {}

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('space-notification:'.$this->notificationId))->dontRelease(),
        ];
    }

    public function handle(NoticePushService $pushService): void
    {
        $pushService->processNotification($this->notificationId);
    }
}
