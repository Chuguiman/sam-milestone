<?php

namespace App\Filament\Resources\SubscriptionResource\Pages;

use App\Filament\Resources\SubscriptionResource;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

class CreateSubscription extends CreateRecord
{
    protected static string $resource = SubscriptionResource::class;

/*     public static function mutateFormDataBeforeCreate(array $data): array
    {
        // Añadir automáticamente el ID de la organización actual
        $data['organization_id'] = auth()->user()->current_team_id;

        // Añadir automáticamente el ID del plan seleccionado
        if (isset($data['plan_id'])) {
            $data['plan_id'] = $data['plan_id'];
            unset($data['plan_id']);
        }
    
        return $data;
    } */

    protected function handleRecordCreation(array $data): Model
    {
        // Asegurarse de que todos los campos requeridos estén presentes
        $data['name'] = 'default';
        $data['type'] = 'regular';
        $data['starts_at'] = now();
        $data['stripe_status'] = 'incomplete';
        
        // Crea el registro usando el modelo
        return static::getModel()::create($data);
    }

    protected function beforeCreate(): void
    {
        // Imprime los datos que se están pasando al modelo
        //dd($this->form->getState());
        
        // O registra los datos para analizarlos después
        Log::info('Datos de creación de suscripción', $this->form->getState());
    }
}
