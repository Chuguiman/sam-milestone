<?php

namespace App\Filament\Cliente\Resources\MonitoredCountryResource\Pages;

use App\Filament\Cliente\Resources\MonitoredCountryResource;
use Filament\Actions;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;

class CreateMonitoredCountry extends CreateRecord
{
    protected static string $resource = MonitoredCountryResource::class;

    /**
     * Obtiene la organización actual
     */
    protected function getOrganization()
    {
        return Filament::getTenant();
    }
    
    /**
     * Modifica los datos antes de guardarlos en la base de datos
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // Añadir automáticamente el ID de la organización actual
        $data['organization_id'] = $this->getOrganization()->id;
        
        return $data;
    }
    
    /**
     * Ejecuta validaciones antes de la creación
     */
    protected function beforeCreate(): void
    {
        $organization = $this->getOrganization();
        
        // Verificar límite de países
        if ($organization->hasReachedMonitoredCountriesLimit()) {
            $limit = $organization->getMonitoredCountriesLimit();
            
            Notification::make()
                ->title('Límite de países alcanzado')
                ->body("Has alcanzado el límite de {$limit} países monitoreados según tu plan. Actualiza tu suscripción para añadir más países.")
                ->danger()
                ->persistent()
                ->send();
                
            $this->halt();
        }
        
        // Verificar si ya monitorea este país
        $countryId = $this->data['country_id'];
        if ($organization->isMonitoringCountry($countryId)) {
            Notification::make()
                ->title('País ya monitoreado')
                ->body('Este país ya está en tu lista de monitoreo. Puedes editar su configuración desde la lista.')
                ->warning()
                ->send();
                
            $this->halt();
        }
    }
    
    /**
     * Ejecuta acciones después de la creación
     */
    protected function afterCreate(): void
    {
        Notification::make()
            ->title('País añadido')
            ->body('El país ha sido añadido a tu lista de monitoreo exitosamente.')
            ->success()
            ->send();
    }
    
    /**
     * Personaliza la notificación de creación
     */
    protected function getCreatedNotificationTitle(): ?string
    {
        return 'País añadido correctamente';
    }
    
    /**
     * Define la URL de redirección después de la creación
     */
/*     protected function getRedirectUrl(): string
    {
        return MonitoredCountryResource::getUrl('index');
    }
 */
    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
