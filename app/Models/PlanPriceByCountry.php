<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Nnjeim\World\Models\Currency;

class PlanPriceByCountry extends Model
{
    use HasFactory;
    
    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'plan_id',
        'country_code',
        'price',
        'currency',
        'stripe_price_id',
        'billing_interval',
        'original_price',
        'discount_percentage',
        'metadata',
    ];
    
    /**
     * The "booted" method of the model.
     */
    protected static function booted()
    {
        static::creating(function ($price) {
            // Asegurar que currency siempre tenga un valor
            if (empty($price->currency)) {
                $price->currency = 'USD';
            }
            
            // Asegurar que billing_interval siempre tenga un valor
            if (empty($price->billing_interval)) {
                $price->billing_interval = 'monthly';
            }
            
            // Asegurar que discount_percentage siempre tenga un valor
            if ($price->discount_percentage === null) {
                $price->discount_percentage = 0;
            }
        });
    }
    
    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'price' => 'decimal:2',
        'original_price' => 'decimal:2',
        'discount_percentage' => 'decimal:2',
    ];
    
    /**
     * Get the plan that owns the price.
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }
    
    /**
     * Get the country that owns this price.
     */
    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class, 'country_code', 'iso2');
    }
    
    /**
     * Get the currency object.
     */
    public function currencyObject()
    {
        return Currency::where('code', $this->currency)->first();
    }
    
    /**
     * Format the price with currency.
     */
    public function getFormattedPriceAttribute(): string
    {
        $currency = $this->currencyObject();
        $symbol = $currency ? $currency->symbol : '$';
        
        return $symbol . ' ' . number_format($this->price, 2);
    }
    
    /**
     * Calculate monthly price equivalent for display purposes.
     */
    public function getMonthlyEquivalentAttribute(): float
    {
        if ($this->billing_interval === 'annual') {
            return round($this->price / 12, 2);
        }
        
        return $this->price;
    }
    
    /**
     * Apply discount to base price.
     */
    public function applyDiscount($basePrice, $discountPercentage)
    {
        if ($discountPercentage <= 0) {
            return $basePrice;
        }
        
        $discountMultiplier = (100 - $discountPercentage) / 100;
        return round($basePrice * $discountMultiplier, 2);
    }
    
    /**
     * Calculate annual price with discount
     */
    public static function calculateAnnualPrice($basePrice)
    {
        // Annual price: base price * 12 months * 0.7 (30% discount)
        return round($basePrice * 12 * 0.7, 2);
    }
    
    /**
     * Calculate monthly with annual contract price
     */
    public static function calculateMonthlyAnnualPrice($basePrice)
    {
        // Monthly with annual contract: base price * 0.85 (15% discount)
        return round($basePrice * 0.85, 2);
    }
    
    /**
     * Get Stripe price ID based on billing type
     */
    public function getStripePriceIdForBilling($billingType = null)
    {
        $billingType = $billingType ?: $this->billing_interval;
        
        // Si estamos usando metadatos para almacenar diferentes IDs de Stripe
        $metadata = json_decode($this->metadata ?? '{}', true);
        
        switch ($billingType) {
            case 'annual':
                return $metadata['stripe_annual_price_id'] ?? null;
            case 'monthly_annual':
                return $metadata['stripe_monthly_annual_price_id'] ?? null;
            default: // monthly
                return $this->stripe_price_id;
        }
    }
}