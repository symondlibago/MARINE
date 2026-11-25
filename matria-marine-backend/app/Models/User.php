<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, HasRoles;

    /**
     * The legacy string `role` column is retained for backward compatibility
     * with the existing React SPA (which reads `user.role`). Fine-grained
     * access control is handled by spatie/laravel-permission (the `roles`
     * relationship + `hasRole()`), seeded to stay in sync with this column.
     */
    protected $fillable = [
        'name',
        'email',
        'username',
        'password',
        'role',
        'vessel_id',
        'phone',
        'is_active',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
        'is_active' => 'boolean',
    ];

    /**
     * The ship this account sails on, for a master or a cook.
     *
     * Null for the office, who work across the fleet. Which ranks are which,
     * and what each may do, is defined in App\Support\Inventory\VesselAccess —
     * these two helpers only save every caller from importing it.
     */
    public function vessel()
    {
        return $this->belongsTo(\App\Models\Inventory\Vessel::class, 'vessel_id');
    }

    public function isOffice(): bool
    {
        return \App\Support\Inventory\VesselAccess::isOffice($this);
    }

    public function isOnBoard(): bool
    {
        return \App\Support\Inventory\VesselAccess::isOnBoard($this);
    }
}
