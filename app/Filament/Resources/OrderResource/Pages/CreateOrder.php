<?php

namespace App\Filament\Resources\OrderResource\Pages;

use App\Filament\Resources\OrderResource;
use App\Models\Order;
use App\Models\Subscription;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Log;

class CreateOrder extends CreateRecord
{
    protected static string $resource = OrderResource::class;

    protected function handleRecordCreation(array $data): Order
    {
        
        // Si hay suscripción pero faltan campos esenciales
        if (!empty($data['subscription_id']) && (empty($data['organization_id']) || empty($data['plan_id']))) {
            $subscription = Subscription::find($data['subscription_id']);
            
            if ($subscription) {
                // Agregar los campos necesarios
                $data['organization_id'] = $subscription->organization_id;
                $data['plan_id'] = $subscription->plan_id;
                $data['billing_interval'] = $subscription->billing_interval ?? 'monthly';
            }
        }
        
        // Crear la orden con los datos completos
        return Order::create($data);
         
        // Si hay un ID de sesión de Stripe, intentar sincronizar
        if (!empty($data['stripe_session_id'])) {
            $order->syncWithStripe();
        }
        
        return $order;

        
    }
}
