<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContentReport extends Model
{
    use HasFactory, HasPublicId;

    protected $fillable = [
        'space_id',
        'reporter_membership_id',
        'target_type',
        'target_id',
        'reason_type',
        'detail',
        'status',
        'handled_by_membership_id',
        'handled_at',
        'resolution_type',
    ];

    protected function casts(): array
    {
        return [
            'handled_at' => 'datetime',
        ];
    }

    public function space(): BelongsTo
    {
        return $this->belongsTo(Space::class);
    }

    public function reporterMembership(): BelongsTo
    {
        return $this->belongsTo(SpaceMembership::class, 'reporter_membership_id');
    }

    public function handledByMembership(): BelongsTo
    {
        return $this->belongsTo(SpaceMembership::class, 'handled_by_membership_id');
    }
}
