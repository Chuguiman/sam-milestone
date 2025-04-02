<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SubscriptionAddOn extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'subscription_id',
        'add_on_id',
        'quantity',
        'price',
        'stripe_item_id',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'quantity' => 'integer',
        'price' => 'decimal:2',
    ];

    /**
     * Get the subscription that owns the add-on.
     */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    /**
     * Get the add-on associated with the subscription.
     */
    public function addOn(): BelongsTo
    {
        return $this->belongsTo(AddOn::class);
    }

    /**
     * Get the total price for this subscription add-on.
     */
    public function getTotalAttribute(): float
    {
        return $this->price * $this->quantity;
    }

    /**
     * Format the total with currency
     */
    public function getFormattedTotalAttribute(): string
    {
        $currency = $this->addOn->currency ?? 'USD';
        return $currency . ' ' . number_format($this->total, 2);
    }
}