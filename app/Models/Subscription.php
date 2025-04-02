<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Laravel\Cashier\Subscription as CashierSubscription;

class Subscription extends CashierSubscription
{
    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'user_id',              // El usuario que gestiona la suscripción
        'organization_id',      // La organización a la que pertenece la suscripción
        'stripe_id',
        'stripe_status',
        'stripe_price',
        'quantity',
        'billing_interval',     // 'monthly', 'annual-monthly', 'annual-once'
        'is_taxable',           // Si la suscripción es facturable o no
        'starts_at',            // Fecha de inicio
        'trial_ends_at',
        'ends_at',
        'plan_id',
        'discount_id',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'trial_ends_at' => 'datetime',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'is_taxable' => 'boolean',
    ];

    /**
     * Get the plan associated with the subscription.
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /**
     * Get the organization that owns the subscription.
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Get the user who manages this subscription.
     */
    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Get the discount applied to the subscription.
     */
    public function discount(): BelongsTo
    {
        return $this->belongsTo(Discount::class);
    }

    /**
     * Get the add-ons for the subscription.
     */
    public function addOns()
    {
        return $this->belongsToMany(AddOn::class, 'subscription_add_ons')
                    ->withPivot('quantity', 'price', 'stripe_item_id')
                    ->withTimestamps();
    }

    /**
     * Get the orders associated with the subscription.
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /**
     * Get the status of the subscription in a user-friendly format.
     */
    public function getStatusAttribute(): string
    {
        if ($this->onTrial()) {
            return 'Trial';
        }
        
        if ($this->cancelled()) {
            if ($this->onGracePeriod()) {
                return 'Cancelled (Grace Period)';
            }
            
            return 'Cancelled';
        }
        
        if ($this->ended()) {
            return 'Expired';
        }
        
        return ucfirst($this->stripe_status ?? 'Active');
    }

    /**
     * Get the billing interval in human-readable format.
     */
    public function getBillingTypeAttribute(): string
    {
        return match($this->billing_interval) {
            'monthly' => 'Mensual',
            'annual-monthly' => 'Anual (pago mensual)',
            'annual-once' => 'Anual (pago único)',
            default => 'Desconocido'
        };
    }

    /**
     * Calculate the total amount for the subscription including add-ons.
     */
    public function calculateTotal()
    {
        $countryCode = $this->organization->country_code ?? 'US';
        $planPrice = $this->plan ? $this->plan->getPriceForCountry($countryCode) : null;
        
        if (!$planPrice) {
            return 0;
        }
        
        $total = $planPrice->price;
        
        // Add add-ons
        foreach ($this->addOns as $addOn) {
            $total += $addOn->pivot->price * $addOn->pivot->quantity;
        }
        
        // Apply discount if applicable
        if ($this->discount && $this->discount->isValid()) {
            $total = $this->discount->applyDiscount($total);
        }
        
        return $total;
    }

    // ... otros métodos ...
}