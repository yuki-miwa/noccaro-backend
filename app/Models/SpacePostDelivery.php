<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SpacePostDelivery extends Model
{
    use HasFactory;

    protected $fillable = [
        'post_id',
        'recipient_user_id',
    ];

    public function post(): BelongsTo
    {
        return $this->belongsTo(SpacePost::class, 'post_id');
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_user_id');
    }
}
