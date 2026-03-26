<?php

namespace App\Support\Live;

use App\Contracts\Live\LiveChatTokenIssuer;
use App\Exceptions\ApiException;

class DisabledLiveChatTokenIssuer implements LiveChatTokenIssuer
{
    public function configured(): bool
    {
        return false;
    }

    public function issue(
        string $userId,
        array $attributes = [],
        array $capabilities = ['SEND_MESSAGE'],
        ?int $sessionDurationMinutes = null,
    ): IssuedLiveChatToken {
        throw new ApiException('LIVE_CHAT_UNAVAILABLE', 'ライブチャットのトークン発行設定が未完了です。', 503);
    }
}
