<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasTenants;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements FilamentUser, HasTenants
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable;

    protected $fillable = [
        'uuid',
        'name',
        'email',
        'password',
        'can_access_admin_panel',
        'two_factor_secret',
        'two_factor_confirmed_at',
        'locale',
        'timezone',
        'referral_code',
        'referred_by_code',
        'is_suspended',
        'last_login_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'can_access_admin_panel' => 'boolean',
            'two_factor_secret' => 'encrypted',
            'two_factor_confirmed_at' => 'datetime',
            'is_suspended' => 'boolean',
            'last_login_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $user): void {
            if (empty($user->uuid)) {
                $user->uuid = (string) Str::uuid();
            }
        });
    }

    public function canAccessPanel(Panel $panel): bool
    {
        if ($this->is_suspended) {
            return false;
        }

        if ($panel->getId() === 'admin') {
            return $this->can_access_admin_panel || $this->hasRole('super_admin') || $this->roles()->exists();
        }

        return true;
    }

    public function workspaces(): BelongsToMany
    {
        return $this->belongsToMany(Workspace::class, 'workspace_user')
            ->withPivot(['role', 'permissions'])
            ->withTimestamps();
    }

    public function ownedWorkspaces(): HasMany
    {
        return $this->hasMany(Workspace::class, 'owner_id');
    }

    public function getTenants(Panel $panel): Collection
    {
        return $this->workspaces()->where('is_suspended', false)->get();
    }

    public function canAccessTenant(Model $tenant): bool
    {
        return $tenant instanceof Workspace
            && ! $tenant->is_suspended
            && $this->workspaces()->whereKey($tenant->getKey())->exists();
    }
}
