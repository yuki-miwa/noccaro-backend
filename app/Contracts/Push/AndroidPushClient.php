<?php

namespace App\Contracts\Push;

use App\Support\Push\PushSendResult;

interface AndroidPushClient
{
    public function configured(): bool;

    public function send(string $deviceToken, array $notification, array $data): PushSendResult;
}
