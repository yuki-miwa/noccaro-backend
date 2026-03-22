<?php

namespace App\Support\Push;

use App\Contracts\Push\AndroidPushClient;
use Google\Auth\Credentials\ServiceAccountCredentials;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use JsonException;
use Throwable;

class FcmHttpV1Client implements AndroidPushClient
{
    private const ACCESS_TOKEN_SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';

    private ?array $serviceAccount = null;

    public function configured(): bool
    {
        return $this->serviceAccount() !== null && $this->projectId() !== null;
    }

    public function send(string $deviceToken, array $notification, array $data): PushSendResult
    {
        if (! $this->configured()) {
            return PushSendResult::failed('FCM_NOT_CONFIGURED', 'Firebase service account が設定されていません。');
        }

        try {
            $response = Http::timeout((int) config('services.firebase.timeout', 10))
                ->acceptJson()
                ->withToken($this->accessToken())
                ->post($this->endpoint(), [
                    'message' => [
                        'token' => $deviceToken,
                        'notification' => [
                            'title' => (string) ($notification['title'] ?? ''),
                            'body' => (string) ($notification['body'] ?? ''),
                        ],
                        'data' => $this->normalizeData($data),
                        'android' => [
                            'priority' => 'high',
                            'notification' => [
                                'channel_id' => (string) config('services.firebase.android_channel_id', 'default'),
                            ],
                        ],
                    ],
                ]);
        } catch (Throwable $exception) {
            return PushSendResult::failed('HTTP_EXCEPTION', $exception->getMessage());
        }

        if ($response->successful()) {
            return PushSendResult::sent($response->json('name'), $response->body());
        }

        $code = (string) ($response->json('error.status') ?? $response->status());
        $message = (string) ($response->json('error.message') ?? $response->body());

        if ($this->isInvalidTokenResponse($response->status(), $code, $message)) {
            return PushSendResult::invalidToken($code, $message, $response->body());
        }

        return PushSendResult::failed($code, $message, $response->body());
    }

    private function endpoint(): string
    {
        return sprintf('https://fcm.googleapis.com/v1/projects/%s/messages:send', $this->projectId());
    }

    private function projectId(): ?string
    {
        return config('services.firebase.project_id')
            ?: ($this->serviceAccount()['project_id'] ?? null);
    }

    private function accessToken(): string
    {
        $projectId = $this->projectId();
        $cacheKey = 'firebase:fcm:access-token:'.sha1((string) $projectId);
        $cached = Cache::get($cacheKey);

        if (is_array($cached) && isset($cached['access_token'], $cached['expires_at']) && $cached['expires_at'] > now()->addMinute()->timestamp) {
            return $cached['access_token'];
        }

        $credentials = new ServiceAccountCredentials([self::ACCESS_TOKEN_SCOPE], $this->serviceAccount());
        $token = $credentials->fetchAuthToken();
        $accessToken = $token['access_token'] ?? null;
        $ttl = max(60, (int) ($token['expires_in'] ?? 3600) - 60);

        if (! is_string($accessToken) || $accessToken === '') {
            throw new \RuntimeException('Firebase access token を取得できませんでした。');
        }

        Cache::put($cacheKey, [
            'access_token' => $accessToken,
            'expires_at' => now()->addSeconds($ttl)->timestamp,
        ], now()->addSeconds($ttl));

        return $accessToken;
    }

    private function serviceAccount(): ?array
    {
        if ($this->serviceAccount !== null) {
            return $this->serviceAccount;
        }

        $path = config('services.firebase.service_account_path');
        $json = config('services.firebase.service_account_json');

        if (is_string($path) && $path !== '' && is_file($path)) {
            $json = file_get_contents($path) ?: null;
        }

        if (! is_string($json) || trim($json) === '') {
            return $this->serviceAccount = null;
        }

        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $this->serviceAccount = null;
        }

        return $this->serviceAccount = is_array($decoded) ? $decoded : null;
    }

    private function normalizeData(array $data): array
    {
        $normalized = [];

        foreach ($data as $key => $value) {
            $normalized[(string) $key] = match (true) {
                is_bool($value) => $value ? 'true' : 'false',
                $value === null => '',
                default => (string) $value,
            };
        }

        return $normalized;
    }

    private function isInvalidTokenResponse(int $httpStatus, string $code, string $message): bool
    {
        $normalizedMessage = mb_strtolower($message);

        return $code === 'UNREGISTERED'
            || ($code === 'INVALID_ARGUMENT' && str_contains($normalizedMessage, 'token'))
            || ($httpStatus === 404 && str_contains($normalizedMessage, 'registration token'));
    }
}
