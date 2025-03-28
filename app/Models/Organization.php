<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class Organization extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'avatar',
        'support_email',
        'address',
        'city_id',
        'state_id',
        'country_id',
        'postcode',
        'tax_id',
        'vat_country_id',
        'taxable',
        'status',
    ];

    protected $casts = [
        'taxable' => 'boolean',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($organization) {
            if (empty($organization->slug)) {
                $organization->slug = Str::slug($organization->name);
            }
        });

        static::updating(function ($organization) {
            if ($organization->isDirty('name') && !$organization->isDirty('slug')) {
                $organization->slug = Str::slug($organization->name);
            }
        });
    }

    public function members()
    {
        return $this->belongsToMany(User::class, 'organization_user', 'organization_id', 'user_id')->withTimestamps();
    }

    public function country()
    {
        return $this->belongsTo(Country::class);
    }
    
    public function state()
    {
        return $this->belongsTo(State::class);
    }
    
    public function city()
    {
        return $this->belongsTo(City::class);
    }
    
    public function vatCountry()
    {
        return $this->belongsTo(Country::class, 'vat_country_id');
    }

    // Para evitar el error en multi-tenancy
    public function tenant()
    {
        return $this->belongsTo(self::class);
    }

    // Métodos para Filament multi-tenancy
    public function getFilamentTenantRoute(): string
    {
        return route('filament.cliente.tenant.pages.dashboard', ['tenant' => $this->slug]);
    }
    
    public static function getFilamentTenantBySlug(string $slug): ?static
    {
        return static::where('slug', $slug)->first();
    }
}