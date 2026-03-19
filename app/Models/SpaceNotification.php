<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SpaceNotification extends Model
{
    use HasFactory, HasPublicId;

    protected $table = 'notifications';

    protected $fillable = [
        'space_id',
        'source_type',
        'source_id',
        'created_by_membership_id',
        'title',
        'body',
        'target_scope',
        'status',
        'scheduled_at',
        'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_at' => 'datetime',
            'sent_at' => 'datetime',
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
}
