<?php

namespace App\Support\Live;

use DateTimeInterface;

class IssuedLiveChatToken
{
    public function __construct(
        public readonly string $token,
        public readonly DateTimeInterface $tokenExpiresAt,
        public readonly DateTimeInterface $sessionExpiresAt,
        public readonly string $roomArn,
        public readonly string $roomId,
        public readonly string $endpoint,
    ) {}
}
