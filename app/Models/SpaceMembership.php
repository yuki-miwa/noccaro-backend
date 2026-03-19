<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SpaceMembership extends Model
{
    use HasFactory, HasPublicId;

    protected $fillable = [
        'space_id',
        'user_id',
        'role',
        'status',
        'joined_at',
        'approved_at',
        'approved_by_membership_id',
        'left_at',
        'kicked_at',
        'suspended_until',
        'banned_at',
        'mute_until',
        'last_seen_at',
    ];

    protected function casts(): array
    {
        return [
            'joined_at' => 'datetime',
            'approved_at' => 'datetime',
            'left_at' => 'datetime',
            'kicked_at' => 'datetime',
            'suspended_until' => 'datetime',
            'banned_at' => 'datetime',
            'mute_until' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    public function space(): BelongsTo
    {
        return $this->belongsTo(Space::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function approvedByMembership(): BelongsTo
    {
        return $this->belongsTo(self::class, 'approved_by_membership_id');
    }

    public function authoredPosts(): HasMany
    {
        return $this->hasMany(SpacePost::class, 'author_membership_id');
    }

    public function reactions(): HasMany
    {
        return $this->hasMany(SpacePostReaction::class, 'membership_id');
    }

    public function whispers(): HasMany
    {
        return $this->hasMany(MapWhisper::class, 'membership_id');
    }

    public function reports(): HasMany
    {
        return $this->hasMany(ContentReport::class, 'reporter_membership_id');
    }
}
