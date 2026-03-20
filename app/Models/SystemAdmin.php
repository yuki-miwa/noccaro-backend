<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SystemAdmin extends Model
{
    use HasFactory, HasPublicId;

    protected $fillable = [
        'user_id',
        'status',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(SystemAdminAuditLog::class);
    }

    public function createdPosts(): HasMany
    {
        return $this->hasMany(SpacePost::class, 'created_by_system_admin_id');
    }
}
