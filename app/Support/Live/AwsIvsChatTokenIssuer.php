<?php

namespace App\Support\Live;

use App\Contracts\Live\LiveChatTokenIssuer;
use App\Exceptions\ApiException;
use Aws\Credentials\Credentials;
use Aws\Exception\AwsException;
use Aws\ivschat\ivschatClient;
use DateTimeImmutable;

class AwsIvsChatTokenIssuer implements LiveChatTokenIssuer
{
    private ?ivschatClient $client = null;

    public function configured(): bool
    {
        return filled(config('live.chat.room_arn'))
            && filled(config('live.chat.room_id'))
            && filled(config('live.chat.endpoint'))
            && filled(config('live.aws.region'))
            && filled(config('live.aws.access_key_id'))
            && filled(config('live.aws.secret_access_key'));
    }

    public function issue(
        string $userId,
        array $attributes = [],
        array $capabilities = ['SEND_MESSAGE'],
        ?int $sessionDurationMinutes = null,
    ): IssuedLiveChatToken {
        if (! $this->configured()) {
            throw new ApiException('LIVE_CHAT_UNAVAILABLE', 'ライブチャットのトークン発行設定が未完了です。', 503);
        }

        try {
            $result = $this->client()->createChatToken([
                'roomIdentifier' => config('live.chat.room_arn'),
                'userId' => $userId,
                'attributes' => $attributes,
                'capabilities' => $capabilities,
                'sessionDurationInMinutes' => $sessionDurationMinutes ?? (int) config('live.chat.session_duration_minutes', 60),
            ]);
        } catch (AwsException $exception) {
            throw new ApiException(
                'LIVE_CHAT_UNAVAILABLE',
                'ライブチャットのトークン発行に失敗しました。',
                503,
                ['awsError' => $exception->getAwsErrorCode() ?: 'unknown'],
            );
        }

        return new IssuedLiveChatToken(
            token: (string) $result->get('token'),
            tokenExpiresAt: $this->dateTime($result->get('tokenExpirationTime')),
            sessionExpiresAt: $this->dateTime($result->get('sessionExpirationTime')),
            roomArn: (string) config('live.chat.room_arn'),
            roomId: (string) config('live.chat.room_id'),
            endpoint: (string) config('live.chat.endpoint'),
        );
    }

    private function client(): ivschatClient
    {
        if ($this->client instanceof ivschatClient) {
            return $this->client;
        }

        return $this->client = new ivschatClient([
            'version' => 'latest',
            'region' => config('live.aws.region'),
            'credentials' => new Credentials(
                (string) config('live.aws.access_key_id'),
                (string) config('live.aws.secret_access_key'),
            ),
        ]);
    }

    private function dateTime(mixed $value): DateTimeImmutable
    {
        if ($value instanceof DateTimeImmutable) {
            return $value;
        }

        if ($value instanceof \DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($value);
        }

        return new DateTimeImmutable((string) $value);
    }
}
