<?php

namespace App\Contracts\Live;

use App\Support\Live\IssuedLiveChatToken;

interface LiveChatTokenIssuer
{
    public function configured(): bool;

    public function issue(
        string $userId,
        array $attributes = [],
        array $capabilities = ['SEND_MESSAGE'],
        ?int $sessionDurationMinutes = null,
    ): IssuedLiveChatToken;
}
