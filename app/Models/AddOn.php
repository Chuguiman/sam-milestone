<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AddOn extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'code',
        'description',
        'price',
        'currency',
        'stripe_product_id',
        'stripe_price_id',
        'is_active',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'is_active' => 'boolean',
        'price' => 'decimal:2',
    ];

    /**
     * Get the subscription add-ons.
     */
    public function subscriptionAddOns(): HasMany
    {
        return $this->hasMany(SubscriptionAddOn::class);
    }

    /**
     * Format the price with currency
     */
    public function getFormattedPriceAttribute(): string
    {
        return $this->currency . ' ' . number_format($this->price, 2);
    }

    /**
     * Sync add-on data with Stripe
     */
    public function syncWithStripe()
    {
        if (!config('cashier.key')) {
            return false;
        }

        $stripe = new \Stripe\StripeClient(config('cashier.key'));

        try {
            // Create or update product
            if (!$this->stripe_product_id) {
                $product = $stripe->products->create([
                    'name' => $this->name,
                    'description' => $this->description,
                    'active' => $this->is_active,
                    'metadata' => [
                        'add_on_id' => $this->id,
                        'add_on_code' => $this->code
                    ]
                ]);
                $this->stripe_product_id = $product->id;
            } else {
                $stripe->products->update($this->stripe_product_id, [
                    'name' => $this->name,
                    'description' => $this->description,
                    'active' => $this->is_active,
                ]);
            }

            // Create or update price
            if (!$this->stripe_price_id) {
                $stripePrice = $stripe->prices->create([
                    'unit_amount' => (int)($this->price * 100), // Convert to cents
                    'currency' => $this->currency,
                    'product' => $this->stripe_product_id,
                    'recurring' => [
                        'interval' => 'month', // Can be configured to support other intervals
                    ],
                    'metadata' => [
                        'add_on_id' => $this->id
                    ]
                ]);
                
                $this->stripe_price_id = $stripePrice->id;
            }

            $this->save();
            return true;
        } catch (\Exception $e) {
            report($e);
            return false;
        }
    }
}