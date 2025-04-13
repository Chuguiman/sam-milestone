<?php

namespace App\Services;

use App\Models\Subscription;
use Illuminate\Support\Facades\Log;
use Stripe\Stripe;
use Stripe\Checkout\Session;
use Stripe\Exception\ApiErrorException;

class StripeCheckoutService
{
    public function createCheckoutSession(Subscription $subscription)
    {
        //Stripe::setApiKey(config('services.stripe.secret'));

        // Asegurarse de que la clave API esté configurada
        $stripeKey = config('services.stripe.test_secret'); // O 'services.stripe.secret' en producción
    
        if (empty($stripeKey)) {
            Log::error('Clave API de Stripe no configurada');
            throw new \Exception('Clave API de Stripe no configurada en config/services.php');
        }
        
        Stripe::setApiKey($stripeKey);
        
        try {
            // Obtener o crear un cliente en Stripe
            $customer = $this->getOrCreateCustomer($subscription->organization);
            
            // Obtener el price_id del plan
            $priceId = $this->getPriceId($subscription);
            
            // Crear la sesión de checkout
            $session = Session::create([
                'customer' => $customer->id,
                'payment_method_types' => ['card'],
                'line_items' => [
                    [
                        'price' => $priceId,
                        'quantity' => 1,
                    ],
                    // Agregar addons si existen
                    ...$this->getAddonsAsLineItems($subscription),
                ],
                'mode' => 'subscription',
                'success_url' => route('subscription.success', ['subscription' => $subscription->id]) . '?session_id={CHECKOUT_SESSION_ID}',
                'cancel_url' => route('subscription.cancel', ['subscription' => $subscription->id]),
                'client_reference_id' => $subscription->id,
                'metadata' => [
                    'subscription_id' => $subscription->id,
                    'organization_id' => $subscription->organization_id,
                    'plan_id' => $subscription->plan_id,
                ],
            ]);
            
            // Actualizar la suscripción con el ID de sesión
            $subscription->update([
                'metadata' => [
                    'last_checkout_session' => $session->id,
                ]
            ]);
            
            return $session->url;
            
        } catch (ApiErrorException $e) {
            Log::error('Error al crear sesión de checkout en Stripe', [
                'subscription_id' => $subscription->id,
                'error' => $e->getMessage()
            ]);
            
            throw new \Exception('Error al crear sesión de checkout: ' . $e->getMessage());
        }
    }
    
    private function getOrCreateCustomer($organization)
    {
        // Si la organización ya tiene un ID de cliente en Stripe, usarlo
        if ($organization->stripe_id) {
            return \Stripe\Customer::retrieve($organization->stripe_id);
        }
        
        // Crear un nuevo cliente en Stripe
        $customer = \Stripe\Customer::create([
            'name' => $organization->name,
            'email' => $organization->email ?? 'info@' . strtolower(str_replace(' ', '', $organization->name)) . '.com',
            'metadata' => [
                'organization_id' => $organization->id
            ]
        ]);
        
        // Guardar el ID de cliente en la organización
        $organization->update(['stripe_id' => $customer->id]);
        
        return $customer;
    }
    
    private function getPriceId(Subscription $subscription)
    {
        $plan = $subscription->plan;
        
        if (!$plan) {
            throw new \Exception('La suscripción no tiene un plan asociado');
        }
        
        // Si el plan ya tiene un ID de precio en Stripe, usarlo
        if ($plan->stripe_price_id) {
            return $plan->stripe_price_id;
        }
        
        // Aquí deberías implementar lógica para crear o buscar el precio en Stripe
        // Este es solo un ejemplo simplificado
        $price = \Stripe\Price::create([
            'unit_amount' => $subscription->getPlanPriceAttribute()->price * 100,
            'currency' => $subscription->getPlanPriceAttribute()->currency ?? 'usd',
            'recurring' => [
                'interval' => $this->convertBillingInterval($subscription->billing_interval),
            ],
            'product_data' => [
                'name' => $plan->name,
                'metadata' => [
                    'plan_id' => $plan->id
                ]
            ],
        ]);
        
        // Actualizar el plan con el ID de precio
        $plan->update(['stripe_price_id' => $price->id]);
        
        return $price->id;
    }
    
    private function convertBillingInterval($billingInterval)
    {
        return match($billingInterval) {
            'monthly', 'annual-monthly' => 'month',
            'annual-once' => 'year',
            default => 'month'
        };
    }
    
    private function getAddonsAsLineItems(Subscription $subscription)
    {
        $lineItems = [];
        
        foreach ($subscription->addOns as $addon) {
            // Aquí deberías implementar lógica para obtener o crear el precio del addon en Stripe
            // Esto es solo un ejemplo simplificado
            $priceId = $this->getOrCreateAddonPrice($addon, $subscription);
            
            $lineItems[] = [
                'price' => $priceId,
                'quantity' => $addon->pivot->quantity,
            ];
        }
        
        return $lineItems;
    }
    
    private function getOrCreateAddonPrice($addon, $subscription)
    {
        // Lógica para obtener o crear el precio del addon en Stripe
        // Este método debe implementarse según tu lógica de negocio
        
        // Ejemplo simplificado:
        if ($addon->stripe_price_id) {
            return $addon->stripe_price_id;
        }
        
        $price = \Stripe\Price::create([
            'unit_amount' => $addon->pivot->price * 100,
            'currency' => $addon->pivot->currency ?? 'usd',
            'recurring' => [
                'interval' => $this->convertBillingInterval($subscription->billing_interval),
            ],
            'product_data' => [
                'name' => $addon->name,
                'metadata' => [
                    'addon_id' => $addon->id
                ]
            ],
        ]);
        
        // Actualizar el addon con el ID de precio
        $addon->update(['stripe_price_id' => $price->id]);
        
        return $price->id;
    }
}