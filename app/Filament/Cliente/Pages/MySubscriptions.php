<?php

namespace App\Filament\Cliente\Pages;

use App\Models\Country;
use App\Models\PlanPriceByCountry;
use Filament\Pages\Page;
use Filament\Facades\Filament;
use App\Models\Subscription;

class MySubscriptions extends Page
{

    protected static string $view = 'filament.cliente.pages.subscription.my-subscriptions';

    protected static ?string $navigationIcon = 'heroicon-o-credit-card';

    protected static ?string $navigationGroup = 'Settings';

    protected static ?string $navigationLabel = 'Mi Suscripción';

    protected static ?int $navigationSort = 5;
    
    public function getTenant()
    {
        return filament()->getTenant();
    }
    
    public function getSubscription()
    {
        $tenant = $this->getTenant();
        
        if (!$tenant) {
            return null;
        }
        
        $subscription = Subscription::where('organization_id', $tenant->id)
            ->where(function ($query) {
                $query->where('stripe_status', 'active')
                    ->orWhere('stripe_status', 'complete')
                    ->orWhere('stripe_status', 'trialing');
            })
            ->where(function ($query) {
                $query->whereNull('ends_at')
                    ->orWhere('ends_at', '>', now());
            })
            ->latest()
            ->first();
            
        return $subscription;
    }
    
    /* public function getUserLimitInfo()
    {
        $tenant = Filament::getTenant();
        if (!$tenant) {
            return [
                'has_limit' => false,
                'max_users' => 'N/A',
                'current_users' => 0,
                'can_add_more' => false,
            ];
        }
        
        $metadata = json_decode($tenant->metadata ?? '{}', true);
        $maxUsers = $metadata['limits']['max_users'] ?? 0;
        
        // Si maxUsers es 0 o negativo, no hay límite
        if ($maxUsers <= 0) {
            return [
                'has_limit' => false,
                'max_users' => 'Ilimitado',
                'current_users' => $tenant->members()->count(),
                'can_add_more' => true,
            ];
        }
        
        $currentUsers = $tenant->members()->count();
        
        return [
            'has_limit' => true,
            'max_users' => $maxUsers,
            'current_users' => $currentUsers,
            'can_add_more' => $currentUsers < $maxUsers,
        ];
    }
    
    public function getCountryLimitInfo()
    {
        $tenant = Filament::getTenant();
        if (!$tenant) {
            return [
                'has_limit' => false,
                'max_countries' => 'N/A',
                'current_countries' => 0,
                'can_add_more' => false,
            ];
        }
        
        $metadata = json_decode($tenant->metadata ?? '{}', true);
        $maxCountries = $metadata['limits']['max_countries'] ?? 0;
        
        // Si maxCountries es 0 o negativo, no hay límite
        if ($maxCountries <= 0) {
            return [
                'has_limit' => false,
                'max_countries' => 'Ilimitado',
                'current_countries' => $tenant->activeMonitoredCountries()->count(),
                'can_add_more' => true,
            ];
        }
        
        $currentCountries = $tenant->activeMonitoredCountries()->count();
        
        return [
            'has_limit' => true,
            'max_countries' => $maxCountries,
            'current_countries' => $currentCountries,
            'can_add_more' => $currentCountries < $maxCountries,
        ];
    } */

    public function getPlanFeatures()
    {
        $subscription = $this->getSubscription();
        if (!$subscription || !$subscription->plan) {
            return collect();
        }
        
        return $subscription->plan->features()->get()->map(function ($feature) {
            return [
                'id' => $feature->id,
                'name' => $feature->name,
                'code' => $feature->code,
                'description' => $feature->description,
                'value' => $feature->pivot->value,
            ];
        });
    }
    
    public function getPlanPrice()
    {
        $subscription = $this->getSubscription();
        if (!$subscription) {
            return null;
        }
        
        $tenant = $this->getTenant();
        $countryCode = $tenant ? ($tenant->country_code ?? 'CO') : 'CO';
        $billingInterval = $subscription->billing_interval;
        
        // Buscar el precio específico para el plan, país y ciclo de facturación
        $planPrice = PlanPriceByCountry::where('plan_id', $subscription->plan_id)
            ->where('country_code', $countryCode)
            ->where('billing_interval', $billingInterval)
            ->first();
        
        // Si no encuentra un precio exacto para el intervalo de facturación, obtener cualquier precio del plan para ese país
        if (!$planPrice) {
            $planPrice = $subscription->plan->getPriceForCountry($countryCode);
        }
        
        if (!$planPrice) {
            return null;
        }
        
        return [
            'price' => $planPrice->price,
            'currency' => $planPrice->currency,
            'formatted_price' => $planPrice->formatted_price ?? ('$' . number_format($planPrice->price, 2)),
            'billing_interval' => $planPrice->billing_interval,
            'billing_type' => $this->formatBillingType($planPrice->billing_interval),
            'original_price' => $planPrice->original_price,
            'discount_percentage' => $planPrice->discount_percentage,
        ];
    }
    
    protected function formatBillingType($billingInterval)
    {
        return match($billingInterval) {
            'monthly' => 'Mensual',
            'annual' => 'Anual',
            'annual-once' => 'Anual (pago único)',
            'monthly_annual' => 'Mensual anual',
            default => ucfirst($billingInterval)
        };
    }

    public function getCountryCode()
    {
        $tenant = $this->getTenant();
        $countryId = $tenant ? ($tenant->country_id ?? null) : null;
        $countryCode = Country::where('id', $countryId)
            ->value('iso2');
        
        return $countryCode;
    }
    
    /* public function canManageFeatures()
    {
        // Obtener el tenant y el usuario actual
        $tenant = Filament::getTenant();
        $user = auth()->user();
        
        if (!$tenant || !$user) {
            return false;
        }
        
        // Verificar si el usuario es admin mediante el método directo
        return $tenant->userIsAdmin($user);
    }
    
    protected function getHeaderActions(): array
    {
        return [];
    } */
}
