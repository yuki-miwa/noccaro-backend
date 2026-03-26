<?php

return [
    'aws' => [
        'region' => env('AWS_DEFAULT_REGION', 'ap-northeast-1'),
        'access_key_id' => env('AWS_ACCESS_KEY_ID'),
        'secret_access_key' => env('AWS_SECRET_ACCESS_KEY'),
    ],

    'channel_arn' => env('IVS_CHANNEL_ARN', 'arn:aws:ivs:ap-northeast-1:328125385782:channel/CUkr1ExhTsHh'),
    'playback_url' => env('IVS_PLAYBACK_URL', 'https://806fe5b207ac.ap-northeast-1.playback.live-video.net/api/video/v1/ap-northeast-1.328125385782.channel.CUkr1ExhTsHh.m3u8'),
    'ingest_endpoint' => env('IVS_INGEST_ENDPOINT', 'rtmps://806fe5b207ac.global-contribute.live-video.net:443/app/'),
    'stream_key' => env('IVS_STREAM_KEY'),

    'chat' => [
        'room_arn' => env('IVS_CHAT_ROOM_ARN', 'arn:aws:ivschat:ap-northeast-1:328125385782:room/XOwd5szNiUaJ'),
        'room_id' => env('IVS_CHAT_ROOM_ID', 'XOwd5szNiUaJ'),
        'endpoint' => env('IVS_CHAT_ENDPOINT', 'wss://edge.ivschat.ap-northeast-1.amazonaws.com'),
        'session_duration_minutes' => (int) env('IVS_CHAT_SESSION_DURATION_MINUTES', 60),
        'message_max_length' => (int) env('IVS_CHAT_MESSAGE_MAX_LENGTH', 30),
        'cooldown_seconds' => (int) env('IVS_CHAT_COOLDOWN_SECONDS', 3),
    ],
];
