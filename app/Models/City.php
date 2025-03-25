<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class City extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'country_id',
        'state_id',
        'name',
        'country_code',
    ];

    /**
     * Get the country that owns the city.
     */
    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    /**
     * Get the state that owns the city.
     */
    public function state(): BelongsTo
    {
        return $this->belongsTo(State::class);
    }

    public function organizations()
    {
        return $this->hasMany(Organization::class);
    }

    /**
     * Get the full name with state and country.
     */
    public function getFullNameAttribute(): string
    {
        return "{$this->name}, {$this->state->name}, {$this->country->name}";
    }

    /**
     * Get the full name with state, country and flag.
     */
    public function getFullNameWithFlagAttribute(): string
    {
        return "{$this->name}, {$this->state->name}, {$this->country->getFlag()} {$this->country->name}";
    }
}