<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Feature extends Model
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
    ];

    /**
     * Get the plans associated with the feature.
     */
    public function plans(): BelongsToMany
    {
        return $this->belongsToMany(Plan::class, 'plan_features')
                    ->withPivot('value')
                    ->withTimestamps();
    }

    // En el modelo Feature
    public function isCountryMonitoring()
    {
        return $this->type === 'country_monitoring';
    }

    // En el servicio de suscripción
    public function availableCountries(User $user)
    {
        $subscription = $user->activeSubscription;
        if (!$subscription) return collect();
        
        $countryFeature = $subscription->plan->features()
            ->where('type', 'country_monitoring')
            ->first();
            
        if (!$countryFeature) return collect();
        
        $configuredValue = $subscription->featureValues()
            ->where('feature_id', $countryFeature->id)
            ->first()?->value_configured ?? $countryFeature->default_value;
            
        return $configuredValue; // Esto podría ser un número o un array de códigos de país
    }


    // En el modelo Feature
    public function isUserLimit()
    {
        return $this->type === 'user_limit';
    }

    // En el servicio de suscripción
    public function canAddMoreUsers(User $user)
    {
        $subscription = $user->activeSubscription;
        if (!$subscription) return false;
        
        $userLimitFeature = $subscription->plan->features()
            ->where('type', 'user_limit')
            ->first();
            
        if (!$userLimitFeature) return false;
        
        $configuredLimit = $subscription->featureValues()
            ->where('feature_id', $userLimitFeature->id)
            ->first()?->value_configured ?? $userLimitFeature->default_value;
            
        $currentUserCount = $user->teamUsers()->count();
        
        return $currentUserCount < $configuredLimit;
    }

    

}