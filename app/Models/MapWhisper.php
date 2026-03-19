<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MapWhisper extends Model
{
    use HasFactory, HasPublicId;

    protected $fillable = [
        'space_id',
        'membership_id',
        'body',
        'status',
        'grid_key',
        'display_lat',
        'display_lng',
        'display_radius_m',
        'expires_at',
        'hidden_at',
        'removed_at',
        'removed_by_membership_id',
        'report_count',
    ];

    protected function casts(): array
    {
        return [
            'display_lat' => 'decimal:7',
            'display_lng' => 'decimal:7',
            'expires_at' => 'datetime',
            'hidden_at' => 'datetime',
            'removed_at' => 'datetime',
        ];
    }

    public function space(): BelongsTo
    {
        return $this->belongsTo(Space::class);
    }

    public function membership(): BelongsTo
    {
        return $this->belongsTo(SpaceMembership::class, 'membership_id');
    }

    public function removedByMembership(): BelongsTo
    {
        return $this->belongsTo(SpaceMembership::class, 'removed_by_membership_id');
    }
}
