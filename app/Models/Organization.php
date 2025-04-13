<?php

namespace App\Models;

use Filament\Facades\Filament;
use Laravel\Cashier\Billable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
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

    protected $attributes = [
        'status' => 'active',
        'taxable' => false,
    ];
    
    // Método para crear suscripción con Cashier
    public function createSubscription(Plan $plan, $options = [])
    {
        // Mapear opciones de tu formulario a Cashier
        return $this->newSubscription(
            'default', // nombre de la suscripción
            $plan->stripe_price_id // ID del precio de Stripe
        )
        ->create(null, [
            'metadata' => [
                'organization_id' => $this->id,
                'plan_id' => $plan->id,
                // Otros metadatos personalizados
            ]
        ]);
    }

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
        return $this->hasMany(Order::class, 'organization_id');
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



/**
 * Obtiene los países monitoreados por esta organización.
 */
public function monitoredCountries()
{
    return $this->hasMany(MonitoredCountry::class);
}

/**
 * Obtiene los países monitoreados activos.
 */
public function activeMonitoredCountries()
{
    return $this->monitoredCountries()->where('is_active', true);
}

/**
 * Comprueba si la organización está monitoreando un país específico.
 *
 * @param int $countryId
 * @return bool
 */
public function isMonitoringCountry($countryId)
{
    return $this->monitoredCountries()
        ->where('country_id', $countryId)
        ->where('is_active', true)
        ->exists();
}

/**
 * Añade un país para monitorear.
 *
 * @param int $countryId
 * @param array $settings
 * @return MonitoredCountry
 */
public function addMonitoredCountry($countryId, $settings = [])
{
    return $this->monitoredCountries()->updateOrCreate(
        ['country_id' => $countryId],
        [
            'is_active' => true,
            'settings' => $settings,
        ]
    );
}

/**
 * Desactiva el monitoreo de un país.
 *
 * @param int $countryId
 * @return bool
 */
public function removeMonitoredCountry($countryId)
{
    $monitoredCountry = $this->monitoredCountries()
        ->where('country_id', $countryId)
        ->first();
        
    if ($monitoredCountry) {
        $monitoredCountry->is_active = false;
        return $monitoredCountry->save();
    }
    
    return false;
}

/**
 * Obtiene el límite de países que puede monitorear esta organización según su plan.
 * Este método lee el límite desde los metadatos de la organización.
 *
 * @return int 0 significa sin límite
 */
public function getMonitoredCountriesLimit()
{
    $metadata = json_decode($this->metadata ?? '{}', true);
    return $metadata['limits']['max_countries'] ?? 0;
}

/**
 * Verifica si la organización ha alcanzado su límite de países monitoreados.
 *
 * @return bool
 */
public function hasReachedMonitoredCountriesLimit()
{
    $limit = $this->getMonitoredCountriesLimit();
    
    if ($limit <= 0) {
        return false; // Sin límite
    }
    
    $count = $this->activeMonitoredCountries()->count();
    return $count >= $limit;
}

public function activeSubscription()
    {
        return $this->hasOne(Subscription::class)
            ->where(function ($query) {
                $query->where('stripe_status', 'active')
                      ->orWhere('stripe_status', 'complete')  // Agregar este estado
                      ->orWhere('stripe_status', 'trialing');
            })
            ->where(function ($query) {
                $query->whereNull('ends_at')
                    ->orWhere('ends_at', '>', now());
            })
            ->latest();
    }


    public function canManageFeatures()
{
    $user = Auth::user();
    
    if (!$user) {
        return false;
    }
    
    // Intenta obtener la organización del tenant de Filament primero
    $tenant = Filament::getTenant();
    
    // Si no hay tenant, intenta obtenerlo del usuario
    if (!$tenant && method_exists($user, 'organization')) {
        $tenant = $user->organization;
    }
    
    // Si aún no hay tenant, verifica directamente en la base de datos
    if (!$tenant) {
        $tenant = \App\Models\Organization::find($user->organization_id);
    }
    
    // Si definitivamente no hay tenant, no se pueden administrar características
    if (!$tenant) {
        return false;
    }
    
    // Ahora verificamos si el usuario es admin
    if (method_exists($tenant, 'userIsAdmin')) {
        return $tenant->userIsAdmin($user);
    }
    
    // Si no hay método userIsAdmin, verificamos la relación directamente
    $role = DB::table('organization_user')
        ->where('organization_id', $tenant->id)
        ->where('user_id', $user->id)
        ->value('role');
    
    return in_array($role, ['admin', 'owner']);
}

/* public function userIsAdmin(User $user)
{
    return $this->members()
        ->wherePivot('user_id', $user->id)
        ->wherePivotIn('role', ['admin', 'owner'])
        ->exists();
} */

}