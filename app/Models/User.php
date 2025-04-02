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
        'country_code', // Añadido para gestión de precios por país
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

    /**
     * Get the organizations that the user belongs to.
     */
    public function organizations(): BelongsToMany
    {
        return $this->belongsToMany(Organization::class, 'organization_user', 'user_id', 'organization_id')
            ->withPivot('role')
            ->withTimestamps();
    }

    /**
     * Get tenants for Filament Panel.
     */
    public function getTenants(Panel $panel): Collection
    {
        return $this->organizations;
    }
 
    /**
     * Check if user can access a specific tenant.
     */
    public function canAccessTenant(Model $tenant): bool
    {
        if (!$tenant instanceof Organization) {
            return false;
        }
        
        return $this->organizations()->whereKey($tenant->getKey())->exists();
    }

    /**
     * Check if user can access a panel.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return true;
    }

    /**
     * Get the user's current active organization.
     */
    public function currentOrganization()
    {
        // Si hay una organización activa en la sesión, devolver esa
        $organizationId = session('current_organization_id');
        
        if ($organizationId) {
            $organization = $this->organizations->find($organizationId);
            if ($organization) {
                return $organization;
            }
        }
        
        // De lo contrario, devolver la primera organización
        return $this->organizations->first();
    }

    /**
     * Switch the user's context to a different organization.
     */
    public function switchOrganization(Organization $organization): bool
    {
        if (!$this->organizations->contains($organization)) {
            return false;
        }
        
        session(['current_organization_id' => $organization->id]);
        
        return true;
    }

    /**
     * Check if the user has a specific role in an organization.
     */
    public function hasOrganizationRole(Organization $organization, string $role): bool
    {
        return $this->organizations()
            ->where('organizations.id', $organization->id)
            ->wherePivot('role', $role)
            ->exists();
    }

    /**
     * Get the user's role in an organization.
     */
    public function getOrganizationRole(Organization $organization): ?string
    {
        $pivot = $this->organizations()
            ->where('organizations.id', $organization->id)
            ->first()?->pivot;
            
        return $pivot ? $pivot->role : null;
    }

    /**
     * Check if the user is an admin of any organization.
     */
    public function isAdminOfAnyOrganization(): bool
    {
        return $this->organizations()
            ->wherePivotIn('role', ['admin', 'owner'])
            ->exists();
    }

    /**
     * Get all organizations where the user is an admin.
     */
    public function adminOrganizations()
    {
        return $this->organizations()
            ->wherePivotIn('role', ['admin', 'owner']);
    }

    /**
     * Check if the user is an admin of a specific organization.
     */
    public function isAdminOf(Organization $organization): bool
    {
        return $this->hasOrganizationRole($organization, 'admin') || 
               $this->hasOrganizationRole($organization, 'owner');
    }

    /**
     * Boot the model.
     */
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