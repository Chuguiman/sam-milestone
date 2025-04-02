<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Discount extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'code',
        'name',
        'type',
        'value',
        'max_uses',
        'used',
        'expires_at',
        'is_active',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'is_active' => 'boolean',
        'value' => 'decimal:2',
        'max_uses' => 'integer',
        'used' => 'integer',
        'expires_at' => 'datetime',
    ];

    /**
     * Get the subscriptions associated with the discount.
     */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    /**
     * Check if the discount is valid.
     */
    public function isValid(): bool
    {
        if (!$this->is_active) {
            return false;
        }

        if ($this->expires_at && $this->expires_at->isPast()) {
            return false;
        }

        if ($this->max_uses !== null && $this->used >= $this->max_uses) {
            return false;
        }

        return true;
    }

    /**
     * Calculate the discount amount for a given price.
     */
    public function calculateDiscountAmount($price): float
    {
        if ($this->type === 'percentage') {
            return round(($this->value / 100) * $price, 2);
        }

        // Fixed amount discount
        return min($this->value, $price); // Don't discount more than the price
    }

    /**
     * Apply the discount to a given price.
     */
    public function applyDiscount($price): float
    {
        $discountAmount = $this->calculateDiscountAmount($price);
        return round($price - $discountAmount, 2);
    }

    /**
     * Increment the usage counter.
     */
    public function incrementUsage(): void
    {
        $this->increment('used');
    }
}