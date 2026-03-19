<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class SpacePost extends Model
{
    use HasFactory, HasPublicId, SoftDeletes;

    protected $fillable = [
        'space_id',
        'author_membership_id',
        'title',
        'body',
        'status',
        'notify_members',
        'published_at',
        'visible_from',
        'visible_to',
    ];

    protected function casts(): array
    {
        return [
            'notify_members' => 'boolean',
            'published_at' => 'datetime',
            'visible_from' => 'datetime',
            'visible_to' => 'datetime',
        ];
    }

    public function space(): BelongsTo
    {
        return $this->belongsTo(Space::class);
    }

    public function authorMembership(): BelongsTo
    {
        return $this->belongsTo(SpaceMembership::class, 'author_membership_id');
    }

    public function reactions(): HasMany
    {
        return $this->hasMany(SpacePostReaction::class, 'post_id');
    }
}
