<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Laravel\Cashier\Subscription as CashierSubscription;

class Subscription extends CashierSubscription

{
    use HasFactory;

    /**
     * Los atributos que son asignables masivamente.
     * Ajustados exactamente a tu estructura de base de datos.
     */
    protected $fillable = [
        'user_id',
        'type',
        'stripe_id',
        'stripe_status',
        'stripe_price',
        'quantity',
        'trial_ends_at',
        'ends_at',
        'plan_id',
        'plan_price_by_country_id',
        'discount_id',
        'organization_id',
        'billing_interval',
        'is_taxable',
        'starts_at',
        'metadata',
    ];

    /**
     * Los atributos que deben ser convertidos a tipos nativos.
     */
    protected $casts = [
        'trial_ends_at' => 'datetime',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'is_taxable' => 'boolean',
        'metadata' => 'json',
    ];


    public function stripePlan()
    {
        return $this->belongsTo(Plan::class, 'plan_id')
            ->where('stripe_product_id', '!=', null);
    }

    /**
     * Get the plan associated with the subscription.
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /**
     * Get the plan associated with the Plan Price By Country.
     */
    public function planPrice()
    {
        return $this->belongsTo(PlanPriceByCountry::class, 'plan_price_by_country_id');
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
                    ->withPivot('quantity', 'price', 'currency', 'stripe_item_id')
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
        if ($this->trial_ends_at && $this->trial_ends_at->isFuture()) {
            return 'Trial';
        }
        
        if ($this->ends_at) {
            if ($this->ends_at->isFuture()) {
                return 'Cancelled (Grace Period)';
            }
            
            return 'Cancelled';
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
     * Check if the subscription is on a trial period.
     */
    public function onTrial(): bool
    {
        return $this->trial_ends_at && $this->trial_ends_at->isFuture();
    }

    /**
     * Check if the subscription is cancelled.
     */
    public function cancelled(): bool
    {
        return $this->ends_at !== null;
    }

    /**
     * Check if the subscription is on a grace period after cancellation.
     */
    public function onGracePeriod(): bool
    {
        return $this->ends_at && $this->ends_at->isFuture();
    }

    /**
     * Check if the subscription has ended.
     */
    public function ended(): bool
    {
        return $this->ends_at && $this->ends_at->isPast();
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
        if ($this->discount && method_exists($this->discount, 'isValid') && $this->discount->isValid()) {
            $total = $this->discount->applyDiscount($total);
        }
        
        return $total;
    }



    public function getPlanPriceAttribute()
    {
        return DB::table('plan_price_by_countries')
            ->where('plan_id', $this->plan_id)
            ->where('country_code', $this->organization->country_code ?? 'US')
            ->where('billing_interval', $this->billing_interval)
            ->first();
    }

    public function createInitialOrder()
    {
        return Order::createForSubscription($this);
    }

    // Hook para crear orden al crear suscripción
    protected static function booted()
    {
        static::created(function ($subscription) {
            $subscription->createInitialOrder();
        });
    }

    // Método para actualizar orden desde Stripe
    public function updateOrderFromStripe($stripeSession)
    {
        $order = $this->orders()->where('status', 'pending')->first();

        if ($order) {
            $order->update([
                'stripe_session_id' => $stripeSession->id,
                'stripe_checkout_url' => $stripeSession->url,
                'total_amount' => $stripeSession->amount_total / 100,
                'status' => $stripeSession->payment_status === 'paid' ? 'paid' : 'pending'
            ]);

            return $order;
        }

        return null;
    }


    /**
     * Verifica si la suscripción está activa de manera más inclusiva
     */
    public function isActiveFlexible(): bool
    {
        // Considerar varios estados como activos
        $activeStatuses = ['active', 'complete', 'trialing'];
        
        // Verificar si el estado está en los activos
        $statusActive = in_array($this->stripe_status, $activeStatuses);
        
        // Verificar si no ha terminado o está en periodo de gracia
        $notEnded = $this->ends_at === null || $this->ends_at > now();
        
        return $statusActive && $notEnded;
    }
}