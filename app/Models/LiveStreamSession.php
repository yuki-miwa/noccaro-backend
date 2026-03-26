<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LiveStreamSession extends Model
{
    use HasFactory;
    use HasPublicId;

    protected $fillable = [
        'live_thread_id',
        'space_id',
        'status',
        'ivs_channel_arn',
        'ivs_playback_url',
        'ivs_ingest_endpoint',
        'started_by_membership_id',
        'ended_by_membership_id',
        'ended_by_system_admin_id',
        'started_at',
        'ended_at',
        'end_reason',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    public function liveThread(): BelongsTo
    {
        return $this->belongsTo(LiveThread::class);
    }

    public function space(): BelongsTo
    {
        return $this->belongsTo(Space::class);
    }

    public function startedByMembership(): BelongsTo
    {
        return $this->belongsTo(SpaceMembership::class, 'started_by_membership_id');
    }

    public function endedByMembership(): BelongsTo
    {
        return $this->belongsTo(SpaceMembership::class, 'ended_by_membership_id');
    }

    public function endedBySystemAdmin(): BelongsTo
    {
        return $this->belongsTo(SystemAdmin::class, 'ended_by_system_admin_id');
    }
}
