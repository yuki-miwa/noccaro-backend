<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SystemAdminAuditLog extends Model
{
    use HasFactory, HasPublicId;

    public $timestamps = false;

    protected $fillable = [
        'system_admin_id',
        'action',
        'entity_type',
        'entity_public_id',
        'message',
        'metadata',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function systemAdmin(): BelongsTo
    {
        return $this->belongsTo(SystemAdmin::class);
    }
}
