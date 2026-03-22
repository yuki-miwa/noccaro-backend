<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationDelivery extends Model
{
    use HasFactory;

    protected $fillable = [
        'notification_id',
        'post_id',
        'user_id',
        'user_push_device_id',
        'platform',
        'push_token',
        'transport',
        'status',
        'response_code',
        'response_body',
        'fcm_message_id',
        'last_error',
        'sent_at',
        'failed_at',
    ];

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    public function notification(): BelongsTo
    {
        return $this->belongsTo(SpaceNotification::class, 'notification_id');
    }

    public function post(): BelongsTo
    {
        return $this->belongsTo(SpacePost::class, 'post_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(UserPushDevice::class, 'user_push_device_id');
    }
}
