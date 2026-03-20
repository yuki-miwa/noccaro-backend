<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Builder;
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
        'category',
        'author_membership_id',
        'created_by_system_admin_id',
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
            'category' => 'string',
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

    public function createdBySystemAdmin(): BelongsTo
    {
        return $this->belongsTo(SystemAdmin::class, 'created_by_system_admin_id');
    }

    public function reactions(): HasMany
    {
        return $this->hasMany(SpacePostReaction::class, 'post_id');
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(SpacePostDelivery::class, 'post_id');
    }

    public function reads(): HasMany
    {
        return $this->hasMany(SpacePostRead::class, 'post_id');
    }

    public function scopePublishedVisible(Builder $query): Builder
    {
        return $query
            ->where('status', 'published')
            ->where(function (Builder $query): void {
                $query->whereNull('visible_from')->orWhere('visible_from', '<=', now());
            })
            ->where(function (Builder $query): void {
                $query->whereNull('visible_to')->orWhere('visible_to', '>=', now());
            });
    }
}
