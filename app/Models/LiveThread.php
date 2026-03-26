<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LiveThread extends Model
{
    use HasFactory;
    use HasPublicId;

    protected $fillable = [
        'space_id',
        'status',
        'created_by_membership_id',
        'closed_by_membership_id',
        'closed_by_system_admin_id',
        'starts_at',
        'closed_at',
        'close_reason',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function space(): BelongsTo
    {
        return $this->belongsTo(Space::class);
    }

    public function createdByMembership(): BelongsTo
    {
        return $this->belongsTo(SpaceMembership::class, 'created_by_membership_id');
    }

    public function closedByMembership(): BelongsTo
    {
        return $this->belongsTo(SpaceMembership::class, 'closed_by_membership_id');
    }

    public function closedBySystemAdmin(): BelongsTo
    {
        return $this->belongsTo(SystemAdmin::class, 'closed_by_system_admin_id');
    }

    public function streamSessions(): HasMany
    {
        return $this->hasMany(LiveStreamSession::class);
    }
}
