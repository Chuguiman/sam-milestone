<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Plan extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'slug',
        'description',
        'stripe_product_id',
        'is_active',
        'is_default',
        'trial_days',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'is_active' => 'boolean',
        'is_default' => 'boolean',
        'trial_days' => 'integer',
    ];

    /**
     * Get the features associated with the plan.
     */
    public function features(): BelongsToMany
    {
        return $this->belongsToMany(Feature::class, 'plan_features')
                    ->withPivot('value')
                    ->withTimestamps();
    }

    /**
     * Get the prices for the plan in different countries.
     */
    public function prices(): HasMany
    {
        return $this->hasMany(PlanPriceByCountry::class);
    }

    /**
     * Get the subscriptions for the plan.
     */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    /**
     * Get the price for a specific country.
     */
    public function getPriceForCountry(string $countryCode)
    {
        return $this->hasMany(PlanPriceByCountry::class)
            ->where('country_code', $countryCode)
            ->first();
    }

    /**
     * Get all available countries with prices for this plan.
     */
    public function getAvailableCountries()
    {
        return $this->prices()->pluck('country_code')->toArray();
    }

    /**
     * Get default price for the plan.
     */
    public function getDefaultPrice()
    {
        return $this->prices()
                    ->where('country_code', 'US') // Default to US
                    ->first() ?? $this->prices()->first();
    }

    /**
     * Get annual price (12 * monthly) with 20% discount.
     */
    public function getAnnualPrice($countryCode = 'US')
    {
        $monthlyPrice = $this->getPriceForCountry($countryCode);
        
        if (!$monthlyPrice) {
            return null;
        }
        
        // Anual con 20% de descuento
        return round($monthlyPrice->price * 12 * 0.8, 2);
    }

    /**
     * Get monthly price from annual with discount applied (for display).
     */
    public function getMonthlyFromAnnualPrice($countryCode = 'US')
    {
        $annualPrice = $this->getAnnualPrice($countryCode);
        
        if (!$annualPrice) {
            return null;
        }
        
        return round($annualPrice / 12, 2);
    }


    /**
     * Sync plan data with Stripe
     */
    public function syncWithStripe()
    {
        if (!config('cashier.key')) {
            return false;
        }
    
        $stripe = new \Stripe\StripeClient(config('cashier.secret'));
    
        try {
            // Create or update product
            if (!$this->stripe_product_id) {
                $product = $stripe->products->create([
                    'name' => $this->name,
                    'description' => $this->description,
                    'active' => $this->is_active,
                    'metadata' => [
                        'plan_id' => $this->id
                    ]
                ]);
                $this->stripe_product_id = $product->id;
                $this->save();
            } else {
                $stripe->products->update($this->stripe_product_id, [
                    'name' => $this->name,
                    'description' => $this->description,
                    'active' => $this->is_active,
                ]);
            }
    
            // Create or update prices for each country
            foreach ($this->prices as $price) {
                $metadataArray = json_decode($price->metadata ?? '{}', true);
                
                // Determine base price to use (always in USD for Stripe)
                $baseMonthlyPrice = null;
                
                // Get the base price without discounts
                if ($price->original_price && $price->discount_percentage > 0) {
                    // If we have an original price and discount, calculate the base monthly price
                    if ($price->billing_interval === 'annual') {
                        $baseMonthlyPrice = $price->original_price / 12;
                    } else {
                        $baseMonthlyPrice = $price->original_price;
                    }
                } else {
                    // If no original price or discount, use the current price as base
                    if ($price->billing_interval === 'annual') {
                        $baseMonthlyPrice = $price->price / 12;
                    } else {
                        $baseMonthlyPrice = $price->price;
                    }
                }
                
                // Handle regular monthly price
                if (!$price->stripe_price_id || $price->billing_interval === 'monthly') {
                    $monthlyPrice = $stripe->prices->create([
                        'unit_amount' => (int)($baseMonthlyPrice * 100), // Convert to cents
                        'currency' => 'usd', // Siempre en USD
                        'product' => $this->stripe_product_id,
                        'recurring' => [
                            'interval' => 'month',
                            'interval_count' => 1
                        ],
                        'metadata' => [
                            'plan_price_id' => $price->id,
                            'country_code' => $price->country_code,
                            'billing_type' => 'monthly'
                        ]
                    ]);
                    
                    $price->stripe_price_id = $monthlyPrice->id;
                }
                
                // Handle monthly with annual contract (15% discount)
                $monthlyAnnualPrice = PlanPriceByCountry::calculateMonthlyAnnualPrice($baseMonthlyPrice);
                $monthlyAnnualPriceStripe = $stripe->prices->create([
                    'unit_amount' => (int)($monthlyAnnualPrice * 100), // Convert to cents
                    'currency' => 'usd', // Siempre en USD
                    'product' => $this->stripe_product_id,
                    'recurring' => [
                        'interval' => 'month',
                        'interval_count' => 1
                    ],
                    'metadata' => [
                        'plan_price_id' => $price->id,
                        'country_code' => $price->country_code,
                        'billing_type' => 'monthly_annual',
                        'discount' => '15%'
                    ]
                ]);
                
                $metadataArray['stripe_monthly_annual_price_id'] = $monthlyAnnualPriceStripe->id;
                
                // Handle annual payment (30% discount)
                $annualPrice = PlanPriceByCountry::calculateAnnualPrice($baseMonthlyPrice);
                $annualPriceStripe = $stripe->prices->create([
                    'unit_amount' => (int)($annualPrice * 100), // Convert to cents
                    'currency' => 'usd', // Siempre en USD
                    'product' => $this->stripe_product_id,
                    'recurring' => [
                        'interval' => 'year',
                        'interval_count' => 1
                    ],
                    'metadata' => [
                        'plan_price_id' => $price->id,
                        'country_code' => $price->country_code,
                        'billing_type' => 'annual',
                        'discount' => '30%'
                    ]
                ]);
                
                $metadataArray['stripe_annual_price_id'] = $annualPriceStripe->id;
                
                // Save all price IDs in metadata
                $price->metadata = json_encode($metadataArray);
                $price->save();
            }
    
            return true;
        } catch (\Exception $e) {
            report($e);
            return false;
        }
    }
    
    /**
     * Create price for a country
     */
    public function createPriceForCountry($countryCode, $price, $currency = 'USD')
    {
        return $this->prices()->firstOrCreate(
            ['country_code' => $countryCode],
            [
                'price' => $price,
                'currency' => $currency
            ]
        );
    }
    
    /**
     * Get subscription price for Stripe
     */
    public function getStripePriceId($countryCode, $billingType = 'monthly')
    {
        $price = $this->getPriceForCountry($countryCode);
        
        if (!$price) {
            return null;
        }
        
        if ($billingType == 'monthly') {
            return $price->stripe_price_id;
        } else if ($billingType == 'annual') {
            $metadata = json_decode($price->metadata ?? '{}', true);
            return $metadata['stripe_annual_price_id'] ?? null;
        }
        
        return null;
    }

    /**
     * Create all pricing types for a country
     * 
     * @param string $countryCode
     * @param float $basePrice Base monthly price without discounts
     * @param string $currency
     * @return array Array of created price records
     */

    public function createAllPricesForCountry($countryCode, $basePrice, $currency = 'USD')
    {
        // Asegurar que la moneda nunca sea null
        if (empty($currency)) {
            $currency = 'USD';
        }
        
        $prices = [];
        
        // 1. Precio mensual (sin descuento)
        $prices['monthly'] = $this->prices()->updateOrCreate(
            [
                'country_code' => $countryCode,
                'billing_interval' => 'monthly'
            ],
            [
                'price' => round($basePrice),
                'currency' => $currency,
                'discount_percentage' => 0,
            ]
        );
        
        // 2. Precio mensual con contrato anual (15% descuento)
        $monthlyAnnualPrice = $basePrice * 0.85; // 15% descuento
        $prices['monthly_annual'] = $this->prices()->updateOrCreate(
            [
                'country_code' => $countryCode,
                'billing_interval' => 'monthly_annual'
            ],
            [
                'price' => round($monthlyAnnualPrice),
                'currency' => $currency,
                'discount_percentage' => 15,
                'original_price' => $basePrice,
            ]
        );
        
        // 3. Precio anual (30% descuento)
        $annualPrice = $basePrice * 12 * 0.7; // 30% descuento
        $prices['annual'] = $this->prices()->updateOrCreate(
            [
                'country_code' => $countryCode,
                'billing_interval' => 'annual'
            ],
            [
                'price' => round($annualPrice),
                'currency' => $currency,
                'discount_percentage' => 30,
                'original_price' => $basePrice * 12,
            ]
        );
        
        return $prices;
    }
}