<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Stripe\Checkout\Session;
use Stripe\Stripe;

class Order extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'subscription_id',
        'organization_id',
        'plan_id',
        'stripe_session_id',
        'stripe_checkout_url',
        'stripe_payment_intent_id',
        'status', // pending, paid, cancelled
        'subtotal',
        'discount',
        'tax',
        'total_amount',
        'currency',
        'billing_interval',
        'paid_at',
        'failed_reason',
        'payment_method',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'subtotal' => 'decimal:2',
        'discount' => 'decimal:2',
        'tax' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'paid_at' => 'datetime',
    ];

    // Sobreescribir el método create
    public static function create(array $attributes = [])
    {
        // Si hay suscripción pero faltan campos esenciales
        if (!empty($attributes['subscription_id']) && (empty($attributes['organization_id']) || empty($attributes['plan_id']))) {
            $subscription = Subscription::find($attributes['subscription_id']);
            
            if ($subscription) {
                // Agregar los campos necesarios
                $attributes['organization_id'] = $subscription->organization_id;
                $attributes['plan_id'] = $subscription->plan_id;
                $attributes['billing_interval'] = $subscription->billing_interval ?? 'monthly';
            }
        }
        
        // Llamar al método create original con los datos completos
        return static::query()->create($attributes);
    }

    /**
     * Get the organization that owns the order.
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Get the subscription associated with the order.
     */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    /**
     * Get the plan associated with the order.
     */

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /**
     * Format the subtotal with currency
     */
    public function getFormattedSubtotalAttribute(): string
    {
        return $this->currency . ' ' . number_format($this->subtotal, 2);
    }

    /**
     * Format the discount with currency
     */
    public function getFormattedDiscountAttribute(): string
    {
        return $this->currency . ' ' . number_format($this->discount, 2);
    }

    /**
     * Format the tax with currency
     */
    public function getFormattedTaxAttribute(): string
    {
        return $this->currency . ' ' . number_format($this->tax, 2);
    }

    /**
     * Format the total with currency
     */
    public function getFormattedTotalAttribute(): string
    {
        return "{$this->currency} " . number_format($this->total_amount, 2);
    }

    /**
     * Get status badge color
     */
    public function getStatusColorAttribute(): string
    {
        return match($this->status) {
            'paid' => 'success',
            'pending' => 'warning',
            'failed' => 'danger',
            'refunded' => 'info',
            default => 'gray',
        };
    }

    // Métodos de negocio
    public function updateSubscriptionStatus()
    {
        if (!$this->subscription) return;

        switch ($this->status) {
            case 'paid':
                $this->subscription->update([
                    'status' => 'active',
                    'paid_at' => $this->paid_at,
                    'ends_at' => $this->calculateSubscriptionEndDate()
                ]);
                break;
            
            case 'failed':
                $this->subscription->update([
                    'status' => 'payment_failed',
                    'stripe_status' => 'unpaid'
                ]);
                break;
        }
    }

    private function calculateSubscriptionEndDate()
    {
        return match($this->billing_interval) {
            'monthly' => now()->addMonth(),
            'annual-monthly' => now()->addYear(),
            'annual-once' => now()->addYear(),
            default => now()->addMonth()
        };
    }

    public function getPlanPriceAttribute()
    {
        return DB::table('plan_price_by_countries')
            ->where('plan_id', $this->plan_id)
            ->where('country_code', $this->organization->country_code ?? 'US')
            ->where('billing_interval', $this->billing_interval)
            ->first();
    }

    // Método para crear orden inicial
    public static function createForSubscription(Subscription $subscription)
    {
        // Verificar si ya existe una orden para esta suscripción
        $existingOrder = self::where('subscription_id', $subscription->id)->first();
        if ($existingOrder) {
            return $existingOrder;
        }

        // Obtener los detalles del plan con precio
        $planPrice = DB::table('plan_price_by_countries')
            ->where('plan_id', $subscription->plan_id)
            ->where('country_code', $subscription->organization->country_code ?? 'US')
            ->where('billing_interval', $subscription->billing_interval)
            ->first();

        // Calcular detalles financieros
        $subtotal = $planPrice->price ?? 0;
        $discount = self::calculateDiscount($subscription, $subtotal);
        $tax = self::calculateTax($subscription, $subtotal);
        $totalAmount = $subtotal - $discount + $tax;

        // Crear orden con todos los detalles
        return self::create([
            'subscription_id' => $subscription->id,
            'organization_id' => $subscription->organization_id,
            'plan_id' => $subscription->plan_id,
            'status' => 'pending',
            'subtotal' => $subtotal,
            'discount' => $discount,
            'tax' => $tax,
            'total_amount' => $totalAmount,
            'currency' => $planPrice->currency ?? 'USD',
            'billing_interval' => $subscription->billing_interval,
            'stripe_session_id' => null,
        ]);
    }

    // Método para calcular descuento
    public static function calculateDiscount(Subscription $subscription, $subtotal)
    {
        if ($subscription->discount_id) {
            $discount = Discount::find($subscription->discount_id);
            
            if ($discount) {
                return match($discount->type) {
                    'percentage' => $subtotal * ($discount->value / 100),
                    'fixed' => $discount->value,
                    default => 0
                };
            }
        }
    
        return 0;
    }
    
    public static function calculateTax(Subscription $subscription, $subtotal)
    {
        if ($subscription->is_taxable) {
            $taxRate = $subscription->organization->country->tax_rate ?? 0;
            return $subtotal * ($taxRate / 100);
        }
    
        return 0;
    }

    // Método para sincronizar con Stripe
    public function syncWithStripe()
    {
        try {
            // Verificar si hay un ID de sesión
            if (empty($this->stripe_session_id)) {
                Log::warning('No se puede sincronizar la orden con Stripe: ID de sesión vacío', [
                    'order_id' => $this->id
                ]);
                return false;
            }
    
            // Configurar la clave API de Stripe primero
            $stripeKey = config('services.stripe.secret');
            if (empty($stripeKey)) {
                throw new \Exception('Clave API de Stripe no configurada en config/services.php');
            }
            
            \Stripe\Stripe::setApiKey($stripeKey);
            
            // Recuperar detalles de la sesión de Stripe
            $session = \Stripe\Checkout\Session::retrieve($this->stripe_session_id);
            
            // Actualizar detalles de la orden
            $this->update([
                'stripe_checkout_url' => $session->url,
                'status' => $session->payment_status === 'paid' ? 'paid' : 'pending',
                'total_amount' => $session->amount_total / 100,
                'stripe_payment_intent_id' => $session->payment_intent ?? null,
                'paid_at' => $session->payment_status === 'paid' ? now() : null,
            ]);
    
            // Actualizar suscripción si es necesario
            if ($this->subscription && $session->payment_status === 'paid') {
                // Recuperar información de la suscripción de Stripe
                $stripeSubscription = null;
                if ($session->subscription) {
                    $stripeSubscription = \Stripe\Subscription::retrieve($session->subscription);
                }
                
                $this->subscription->update([
                    'status' => 'active',
                    'stripe_status' => $stripeSubscription ? $stripeSubscription->status : 'active',
                    'stripe_id' => $session->subscription,
                    'paid_at' => now(),
                    // Establecer la fecha de finalización basada en el intervalo de facturación
                    'ends_at' => $stripeSubscription ? 
                        now()->addSeconds($stripeSubscription->current_period_end - $stripeSubscription->current_period_start) :
                        $this->subscription->calculateEndDate(),
                ]);
            }
    
            return true;
        } catch (\Exception $e) {
            // Registrar error
            Log::error('Error al sincronizar orden con Stripe', [
                'order_id' => $this->id,
                'error' => $e->getMessage()
            ]);
    
            return false;
        }
    }

    public function createOrderFromSuccessfulPayment($stripeData)
    {
        // Obtener detalles del plan y precios
        $planPrice = $this->getPlanPriceAttribute();
        
        // Calcular importes
        $subtotal = $planPrice->price ?? 0;
        $discount = 0;
        $tax = 0;
        
        // Calcular descuento directamente
        if ($this->discount_id) {
            $discountModel = Discount::find($this->discount_id);
            
            if ($discountModel) {
                $discount = match($discountModel->type) {
                    'percentage' => $subtotal * ($discountModel->value / 100),
                    'fixed' => $discountModel->value,
                    default => 0
                };
            }
        }
        
        // Calcular impuestos directamente
        if ($this->is_taxable && $this->organization) {
            $taxRate = $this->organization->country->tax_rate ?? 0;
            $tax = $subtotal * ($taxRate / 100);
        }
        
        $totalAmount = $subtotal - $discount + $tax;
        
        // Crear la orden con estado pagado
        return Order::create([
            'subscription_id' => $this->id,
            'organization_id' => $this->organization_id,
            'plan_id' => $this->plan_id,
            'status' => 'paid',
            'subtotal' => $subtotal,
            'discount' => $discount,
            'tax' => $tax,
            'total_amount' => $totalAmount,
            'currency' => $planPrice->currency ?? 'USD',
            'billing_interval' => $this->billing_interval,
            'stripe_session_id' => $stripeData['session_id'] ?? null,
            'stripe_payment_intent_id' => $stripeData['payment_intent_id'] ?? null,
            'paid_at' => now(),
            'payment_method' => $stripeData['payment_method'] ?? 'stripe',
        ]);
    }

    protected static function booted()
    {
        static::creating(function ($order) {
            // Si hay suscripción y falta organization_id o plan_id
            if ($order->subscription_id && (!$order->organization_id || !$order->plan_id)) {
                $subscription = Subscription::find($order->subscription_id);
                
                if ($subscription) {
                    $order->organization_id = $subscription->organization_id;
                    $order->plan_id = $subscription->plan_id;
                    $order->billing_interval = $subscription->billing_interval ?? 'monthly';
                }
            }
        });
    }

    
}