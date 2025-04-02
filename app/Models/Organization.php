<?php

namespace App\Models;

use Laravel\Cashier\Billable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Organization extends Model
{
    use HasFactory, Billable; // Añadido el trait Billable para Cashier

    protected $fillable = [
        'name',
        'slug',
        'avatar',
        'support_email',
        'address',
        'city_id',
        'state_id',
        'country_id',
        'country_code',
        'currency',
        'postcode',
        'tax_id',
        'vat_country_id',
        'taxable',
        'status',
    ];

    protected $casts = [
        'taxable' => 'boolean',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($organization) {
            if (empty($organization->slug)) {
                $organization->slug = Str::slug($organization->name);
            }
            
            // Si no se especifica un country_code pero hay un country_id
            if (empty($organization->country_code) && !empty($organization->country_id)) {
                $country = Country::find($organization->country_id);
                if ($country) {
                    $organization->country_code = $country->code;
                }
            }
            
            // Si no se especifica moneda, usar USD por defecto
            if (empty($organization->currency)) {
                $organization->currency = 'USD';
            }
        });

        static::updating(function ($organization) {
            if ($organization->isDirty('name') && !$organization->isDirty('slug')) {
                $organization->slug = Str::slug($organization->name);
            }
            
            // Actualizar country_code si cambia country_id
            if ($organization->isDirty('country_id') && !$organization->isDirty('country_code')) {
                $country = Country::find($organization->country_id);
                if ($country) {
                    $organization->country_code = $country->code;
                }
            }
        });
    }

    /**
     * Get the route key for the model.
     */
    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /**
     * Get the users that belong to the organization.
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'organization_user', 'organization_id', 'user_id')
                    ->withPivot('role')
                    ->withTimestamps();
    }

    /**
     * Get the country that owns the organization.
     */
    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }
    
    /**
     * Get the state that owns the organization.
     */
    public function state(): BelongsTo
    {
        return $this->belongsTo(State::class);
    }
    
    /**
     * Get the city that owns the organization.
     */
    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }
    
    /**
     * Get the VAT country that owns the organization.
     */
    public function vatCountry(): BelongsTo
    {
        return $this->belongsTo(Country::class, 'vat_country_id');
    }

    /**
     * Para evitar el error en multi-tenancy
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(self::class);
    }

    /**
     * Métodos para Filament multi-tenancy
     */
    public function getFilamentTenantRoute(): string
    {
        return route('filament.cliente.tenant.pages.dashboard', ['tenant' => $this->slug]);
    }
    
    public static function getFilamentTenantBySlug(string $slug): ?static
    {
        return static::where('slug', $slug)->first();
    }

    /**
     * Get the current subscription plan.
     */
    public function getCurrentPlanAttribute()
    {
        $subscription = $this->subscription('default');
        
        if (!$subscription) {
            return null;
        }
        
        return $subscription->plan;
    }

    /**
     * Check if the organization has a specific feature.
     */
    public function hasFeature(string $featureCode): bool
    {
        if (!$this->current_plan) {
            return false;
        }
        
        return $this->current_plan->features()
                    ->where('code', $featureCode)
                    ->exists();
    }

    /**
     * Get the feature value.
     */
    public function getFeatureValue(string $featureCode, $default = null)
    {
        if (!$this->current_plan) {
            return $default;
        }
        
        $feature = $this->current_plan->features()
                        ->where('code', $featureCode)
                        ->first();
                        
        return $feature ? $feature->pivot->value : $default;
    }

    /**
     * Get the organization's orders.
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'tenant_id');
    }

    /**
     * Determine if the organization has a specific role.
     */
    public function hasRole(User $user, string $role): bool
    {
        return $this->members()
            ->wherePivot('user_id', $user->id)
            ->wherePivot('role', $role)
            ->exists();
    }

    /**
     * Get the role of a user in this organization.
     */
    public function getUserRole(User $user): ?string
    {
        $pivot = $this->members()
            ->wherePivot('user_id', $user->id)
            ->first()?->pivot;
            
        return $pivot ? $pivot->role : null;
    }

    /**
     * Add a user to the organization with a specific role.
     */
    public function addUserWithRole(User $user, string $role): void
    {
        $this->members()->attach($user->id, ['role' => $role]);
    }

    /**
     * Check if user is an admin of this organization.
     */
    public function userIsAdmin(User $user): bool
    {
        return $this->hasRole($user, 'admin') || $this->hasRole($user, 'owner');
    }

    /**
     * Get all admins of the organization.
     */
    public function admins()
    {
        return $this->members()->wherePivotIn('role', ['admin', 'owner']);
    }

    /**
     * Get the owner of the organization.
     */
    public function owner()
    {
        return $this->members()->wherePivot('role', 'owner')->first();
    }
}