<?php

namespace App\Services;

use App\Models\Organization;
use App\Models\User;
use App\Models\Feature;
use App\Models\MonitoredCountry;
use App\Models\Country;

class SubscriptionFeaturesService
{
    /**
     * Verifica si una organización puede monitorear más países
     */
    public function canAddMoreCountries(Organization $organization): bool
    {
        // Si no hay suscripción activa, no puede añadir países
        $subscription = $organization->activeSubscription;
        if (!$subscription) {
            return false;
        }

        // Verificar usando los metadatos de límites de la organización
        $metadata = json_decode($organization->metadata ?? '{}', true);
        $maxCountries = $metadata['limits']['max_countries'] ?? 0;
        
        // Si maxCountries es 0 o negativo, no hay límite
        if ($maxCountries <= 0) {
            return true;
        }
        
        $currentCount = $organization->activeMonitoredCountries()->count();
        return $currentCount < $maxCountries;
    }

    /**
     * Obtiene los países disponibles para monitoreo según la suscripción
     */
    public function getAvailableCountriesForMonitoring(Organization $organization)
    {
        // Obtener todos los países disponibles en el sistema
        $allCountries = Country::all();
        
        // Verificar límite de países
        $metadata = json_decode($organization->metadata ?? '{}', true);
        $maxCountries = $metadata['limits']['max_countries'] ?? 0;
        
        // Si no hay límite (0 o negativo), devolver todos los países
        if ($maxCountries <= 0) {
            return $allCountries;
        }
        
        // Si hay un límite, verificar cuántos países ya están siendo monitoreados
        $currentMonitoredCount = $organization->activeMonitoredCountries()->count();
        
        // Si ya alcanzó el límite, devolver solo los países que ya monitorea
        if ($currentMonitoredCount >= $maxCountries) {
            $monitoredCountryIds = $organization->activeMonitoredCountries()
                ->pluck('country_id')
                ->toArray();
            
            return $allCountries->whereIn('id', $monitoredCountryIds);
        }
        
        // Si no ha alcanzado el límite, devolver todos los países
        return $allCountries;
    }

    /**
     * Obtiene información sobre los límites de usuarios
     */
    public function getUserLimitInfo(Organization $organization): array
    {
        $metadata = json_decode($organization->metadata ?? '{}', true);
        $maxUsers = $metadata['limits']['max_users'] ?? 0;
        
        // Si maxUsers es 0 o negativo, no hay límite
        if ($maxUsers <= 0) {
            return [
                'has_limit' => false,
                'max_users' => 'Ilimitado',
                'current_users' => $organization->members()->count(),
                'can_add_more' => true,
            ];
        }
        
        $currentUsers = $organization->members()->count();
        
        return [
            'has_limit' => true,
            'max_users' => $maxUsers,
            'current_users' => $currentUsers,
            'can_add_more' => $currentUsers < $maxUsers,
        ];
    }

    /**
     * Obtiene información sobre los límites de países
     */
    public function getCountryLimitInfo(Organization $organization): array
    {
        $metadata = json_decode($organization->metadata ?? '{}', true);
        $maxCountries = $metadata['limits']['max_countries'] ?? 0;
        
        // Si maxCountries es 0 o negativo, no hay límite
        if ($maxCountries <= 0) {
            return [
                'has_limit' => false,
                'max_countries' => 'Ilimitado',
                'current_countries' => $organization->activeMonitoredCountries()->count(),
                'can_add_more' => true,
            ];
        }
        
        $currentCountries = $organization->activeMonitoredCountries()->count();
        
        return [
            'has_limit' => true,
            'max_countries' => $maxCountries,
            'current_countries' => $currentCountries,
            'can_add_more' => $currentCountries < $maxCountries,
        ];
    }

    /**
     * Verifica si un usuario puede gestionar características en su organización
     */
    public function canManageFeatures(User $user): bool
    {
        return $user->organization->userIsAdmin($user);
    }
}