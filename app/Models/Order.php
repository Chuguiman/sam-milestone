<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Order extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'organization_id',
        'subscription_id',
        'subtotal',
        'discount',
        'tax',
        'total',
        'currency',
        'status',
        'stripe_payment_intent_id',
        'stripe_payment_status',
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
        'total' => 'decimal:2',
    ];

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
        return $this->currency . ' ' . number_format($this->total, 2);
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
            default => 'secondary',
        };
    }
}