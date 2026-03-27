<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LiveThreadSchedule extends Model
{
    use HasFactory;
    use HasPublicId;

    protected $fillable = [
        'space_id',
        'status',
        'starts_at',
        'ends_at',
        'area_center_lat',
        'area_center_lng',
        'area_radius_m',
        'created_by_membership_id',
        'updated_by_membership_id',
        'activated_live_thread_id',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'area_center_lat' => 'float',
            'area_center_lng' => 'float',
            'area_radius_m' => 'integer',
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

    public function updatedByMembership(): BelongsTo
    {
        return $this->belongsTo(SpaceMembership::class, 'updated_by_membership_id');
    }

    public function activatedLiveThread(): BelongsTo
    {
        return $this->belongsTo(LiveThread::class, 'activated_live_thread_id');
    }
}
