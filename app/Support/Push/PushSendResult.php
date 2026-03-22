<?php

namespace App\Support\Push;

class PushSendResult
{
    private function __construct(
        public readonly string $status,
        public readonly ?string $messageId = null,
        public readonly ?string $code = null,
        public readonly ?string $message = null,
        public readonly ?string $rawResponse = null,
    ) {}

    public static function sent(?string $messageId = null, ?string $rawResponse = null): self
    {
        return new self('sent', $messageId, null, null, $rawResponse);
    }

    public static function invalidToken(?string $code = null, ?string $message = null, ?string $rawResponse = null): self
    {
        return new self('invalid_token', null, $code, $message, $rawResponse);
    }

    public static function failed(?string $code = null, ?string $message = null, ?string $rawResponse = null): self
    {
        return new self('failed', null, $code, $message, $rawResponse);
    }

    public function isSent(): bool
    {
        return $this->status === 'sent';
    }

    public function isInvalidToken(): bool
    {
        return $this->status === 'invalid_token';
    }
}
