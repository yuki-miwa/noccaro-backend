<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MemberAction extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'space_id',
        'target_membership_id',
        'acted_by_membership_id',
        'action_type',
        'reason',
        'starts_at',
        'ends_at',
        'related_report_id',
        'metadata',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'created_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function space(): BelongsTo
    {
        return $this->belongsTo(Space::class);
    }

    public function targetMembership(): BelongsTo
    {
        return $this->belongsTo(SpaceMembership::class, 'target_membership_id');
    }

    public function actedByMembership(): BelongsTo
    {
        return $this->belongsTo(SpaceMembership::class, 'acted_by_membership_id');
    }

    public function relatedReport(): BelongsTo
    {
        return $this->belongsTo(ContentReport::class, 'related_report_id');
    }
}
