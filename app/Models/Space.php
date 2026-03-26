<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Space extends Model
{
    use HasFactory, HasPublicId, SoftDeletes;

    protected $fillable = [
        'name',
        'space_code',
        'description',
        'join_policy',
        'status',
        'max_owner_count',
        'whisper_ttl_minutes',
        'whisper_auto_hide_report_threshold',
        'whisper_rate_limit_per_minute',
        'whisper_rate_limit_per_10min',
        'whisper_max_length',
        'location_grid_meters',
        'location_jitter_enabled',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'location_jitter_enabled' => 'boolean',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(SpaceMembership::class);
    }

    public function posts(): HasMany
    {
        return $this->hasMany(SpacePost::class);
    }

    public function whispers(): HasMany
    {
        return $this->hasMany(MapWhisper::class);
    }

    public function reports(): HasMany
    {
        return $this->hasMany(ContentReport::class);
    }

    public function approvedCreationRequests(): HasMany
    {
        return $this->hasMany(SpaceCreationRequest::class, 'approved_space_id');
    }

    public function liveThreads(): HasMany
    {
        return $this->hasMany(LiveThread::class);
    }

    public function liveStreamSessions(): HasMany
    {
        return $this->hasMany(LiveStreamSession::class);
    }
}
