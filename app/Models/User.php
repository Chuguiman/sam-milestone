<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;

use BezhanSalleh\FilamentShield\Facades\FilamentShield;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use BezhanSalleh\FilamentShield\Traits\HasPanelShield;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasTenants;
use Filament\Panel;
use Illuminate\Support\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements FilamentUser, HasTenants
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable, HasRoles, HasPanelShield;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'avatar',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function organizations(): BelongsToMany
    {
        return $this->belongsToMany(Organization::class, 'organization_user', 'user_id', 'organization_id')
            ->withTimestamps();
    }

    public function getTenants(Panel $panel): Collection
    {
        return $this->organizations;
    }
 
    public function canAccessTenant(Model $tenant): bool
    {
        if (!$tenant instanceof Organization) {
            return false;
        }
        
        return $this->organizations()->whereKey($tenant->getKey())->exists();
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return true;
    }

    protected static function booted(): void
    {
        if (config('filament-shield.cliente_user.enabled', false)) {
            FilamentShield::createRole(name:config('filament-shield.cliente_user.name', 'cliente_user'));
            static::created(function(User $user) {
                $user->assignRole(config('filament-shield.cliente_user.name', 'cliente_user'));
            });
            static::deleting(function(User $user) {
                $user->removeRole(config('filament-shield.cliente_user.name', 'cliente_user'));
            });
        }
    }
}
