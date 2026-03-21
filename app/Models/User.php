<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasPublicId, Notifiable, SoftDeletes;

    protected $fillable = [
        'email',
        'display_name',
        'password',
        'status',
        'notifications_enabled',
        'last_login_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'notifications_enabled' => 'boolean',
            'password' => 'hashed',
        ];
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(SpaceMembership::class);
    }

    public function pushDevices(): HasMany
    {
        return $this->hasMany(UserPushDevice::class);
    }

    public function postReads(): HasMany
    {
        return $this->hasMany(SpacePostRead::class);
    }

    public function targetedPostDeliveries(): HasMany
    {
        return $this->hasMany(SpacePostDelivery::class, 'recipient_user_id');
    }

    public function personalPostDeliveries(): HasMany
    {
        return $this->targetedPostDeliveries();
    }

    public function createdSpaces(): HasMany
    {
        return $this->hasMany(Space::class, 'created_by_user_id');
    }

    public function spaceCreationRequests(): HasMany
    {
        return $this->hasMany(SpaceCreationRequest::class, 'requester_user_id');
    }

    public function futurePrimaryOwnerRequests(): HasMany
    {
        return $this->hasMany(SpaceCreationRequest::class, 'future_primary_owner_user_id');
    }

    public function systemAdmin(): HasOne
    {
        return $this->hasOne(SystemAdmin::class);
    }
}
