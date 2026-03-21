<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SpaceCreationRequest extends Model
{
    use HasFactory, HasPublicId;

    protected $fillable = [
        'requester_user_id',
        'future_primary_owner_user_id',
        'requested_space_name',
        'requested_space_code',
        'requested_join_policy',
        'status',
        'approved_space_id',
        'reviewed_by_system_admin_id',
        'reviewed_at',
        'approved_at',
        'rejected_at',
        'rejection_visible_until',
    ];

    protected function casts(): array
    {
        return [
            'reviewed_at' => 'datetime',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
            'rejection_visible_until' => 'datetime',
        ];
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_user_id');
    }

    public function futurePrimaryOwner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'future_primary_owner_user_id');
    }

    public function approvedSpace(): BelongsTo
    {
        return $this->belongsTo(Space::class, 'approved_space_id');
    }

    public function reviewedBySystemAdmin(): BelongsTo
    {
        return $this->belongsTo(SystemAdmin::class, 'reviewed_by_system_admin_id');
    }
}
