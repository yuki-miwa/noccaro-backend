<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ApiException;
use App\Support\Api\ApiResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class MeController extends ApiController
{
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();

        return $this->ok([
            'user' => ApiResource::user($user),
            'notificationSettings' => [
                'enabled' => $user->notifications_enabled,
            ],
            'profile' => [
                'pendingEmail' => null,
            ],
        ]);
    }

    public function notificationSettings(Request $request): JsonResponse
    {
        return $this->ok([
            'enabled' => $request->user()->notifications_enabled,
        ]);
    }

    public function updateNotificationSettings(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'enabled' => ['required', 'boolean'],
        ]);

        $user = $request->user();
        $user->forceFill([
            'notifications_enabled' => $payload['enabled'],
        ])->save();

        return $this->ok([
            'enabled' => $user->notifications_enabled,
        ]);
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'displayName' => [
                'sometimes',
                'required',
                'string',
                'min:1',
                'max:100',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (trim((string) $value) === '') {
                        $fail('表示名を入力してください。');
                    }
                },
            ],
            'email' => ['sometimes', 'required', 'email', 'max:255'],
            'currentPassword' => ['nullable', 'string'],
        ]);

        if (! array_key_exists('displayName', $payload) && ! array_key_exists('email', $payload)) {
            throw new ApiException(
                'VALIDATION_ERROR',
                'displayName または email のいずれかを指定してください。',
                422,
                [
                    'field' => 'displayName',
                    'errors' => [
                        'displayName' => ['displayName または email のいずれかを指定してください。'],
                    ],
                ],
            );
        }

        $user = $request->user();
        $nextDisplayName = array_key_exists('displayName', $payload)
            ? trim((string) $payload['displayName'])
            : $user->display_name;
        $nextEmail = array_key_exists('email', $payload)
            ? strtolower(trim((string) $payload['email']))
            : $user->email;
        $emailChanged = $nextEmail !== strtolower((string) $user->email);

        if ($emailChanged) {
            if (! array_key_exists('currentPassword', $payload) || trim((string) $payload['currentPassword']) === '') {
                throw new ApiException(
                    'VALIDATION_ERROR',
                    'メールアドレスを変更するには現在のパスワードが必要です。',
                    422,
                    [
                        'field' => 'currentPassword',
                        'errors' => [
                            'currentPassword' => ['メールアドレスを変更するには現在のパスワードが必要です。'],
                        ],
                    ],
                );
            }

            if (! Hash::check((string) $payload['currentPassword'], (string) $user->password)) {
                throw new ApiException(
                    'CURRENT_PASSWORD_INVALID',
                    '現在のパスワードが正しくありません。',
                    422,
                    ['field' => 'currentPassword'],
                );
            }

            $duplicate = $user->newQuery()
                ->whereRaw('LOWER(email) = ?', [$nextEmail])
                ->whereKeyNot($user->getKey())
                ->exists();

            if ($duplicate) {
                throw new ApiException(
                    'EMAIL_ALREADY_TAKEN',
                    'このメールアドレスはすでに使われています。',
                    409,
                    ['field' => 'email'],
                );
            }

            $user->email = $nextEmail;
            $user->email_verified_at = null;
        }

        $user->display_name = $nextDisplayName;
        $user->save();

        return $this->ok([
            'user' => ApiResource::user($user->fresh()),
            'profileUpdate' => [
                'emailChangeRequiresVerification' => false,
                'pendingEmail' => null,
            ],
        ]);
    }
}
