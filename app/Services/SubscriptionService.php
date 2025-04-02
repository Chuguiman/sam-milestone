<?php

namespace App\Services;

use App\Models\AddOn;
use App\Models\Country;
use App\Models\Discount;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\PlanPriceByCountry;
use App\Models\Subscription;
use Illuminate\Support\Facades\Log;
use Stripe\StripeClient;

class SubscriptionService
{
    protected $stripe;
    
    public function __construct()
    {
        $this->stripe = new StripeClient(config('cashier.secret'));
    }
    
    /**
     * Crear una sesión de checkout en Stripe para la suscripción
     *
     * @param Subscription $subscription
     * @return string|null URL de la sesión de checkout o null si ocurre un error
     */
    public function createCheckoutSession(Subscription $subscription): ?string
    {
        try {
            $organization = $subscription->organization;
            if (!$organization) {
                throw new \Exception('Suscripción sin organización asociada');
            }
            
            // Asegurarse de que existe el cliente en Stripe
            $stripeCustomer = $this->getOrCreateStripeCustomer($organization);
            
            // Preparar los elementos de la suscripción
            $lineItems = $this->prepareLineItems($subscription);
            
            // Crear URLs de éxito y cancelación
            $successUrl = route('filament.cliente.payment.success', ['organizationId' => $organization->id]);
            $cancelUrl = route('filament.cliente.payment.cancel', ['organizationId' => $organization->id]);
            
            // Aplicar cupón si existe un descuento
            $discountId = null;
            if ($subscription->discount_id) {
                $discount = Discount::find($subscription->discount_id);
                if ($discount && $discount->stripe_coupon_id) {
                    $discountId = ['coupon' => $discount->stripe_coupon_id];
                }
            }
            
            // Crear la sesión de checkout en Stripe
            $checkoutSession = $this->stripe->checkout->sessions->create([
                'customer' => $stripeCustomer->id,
                'line_items' => $lineItems,
                'mode' => 'subscription',
                'success_url' => $successUrl,
                'cancel_url' => $cancelUrl,
                'discounts' => $discountId ? [$discountId] : [],
                'client_reference_id' => $subscription->id,
                'metadata' => [
                    'subscription_id' => $subscription->id,
                    'organization_id' => $organization->id,
                ],
                'subscription_data' => [
                    'metadata' => [
                        'subscription_id' => $subscription->id,
                        'organization_id' => $organization->id,
                    ],
                ],
            ]);
            
            // Actualizar la suscripción con información de la sesión
            $subscription->stripe_checkout_id = $checkoutSession->id;
            $subscription->save();
            
            return $checkoutSession->url;
        } catch (\Exception $e) {
            Log::error('Error al crear sesión de checkout: ' . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Obtener o crear cliente en Stripe para la organización
     *
     * @param Organization $organization
     * @return \Stripe\Customer
     */
    protected function getOrCreateStripeCustomer(Organization $organization)
    {
        // Si ya tiene un cliente en Stripe, devolverlo
        if ($organization->stripe_id) {
            return $this->stripe->customers->retrieve($organization->stripe_id);
        }
        
        // Crear nuevo cliente en Stripe
        $customer = $this->stripe->customers->create([
            'name' => $organization->name,
            'email' => $organization->support_email ?: $organization->members()->first()?->email,
            'metadata' => [
                'organization_id' => $organization->id,
            ],
        ]);
        
        // Guardar el ID de cliente en la organización
        $organization->stripe_id = $customer->id;
        $organization->save();
        
        return $customer;
    }
    
    /**
     * Preparar los elementos de línea para la suscripción
     *
     * @param Subscription $subscription
     * @return array
     */
    protected function prepareLineItems(Subscription $subscription): array
    {
        $lineItems = [];
        $organization = $subscription->organization;
        $countryCode = $this->getCountryCode($organization);
        
        // Añadir el plan principal
        $plan = $subscription->plan;
        if ($plan) {
            $stripePriceId = $this->getStripePriceIdForPlan($plan, $countryCode, $subscription->billing_interval);
            
            if ($stripePriceId) {
                $lineItems[] = [
                    'price' => $stripePriceId,
                    'quantity' => 1,
                ];
            }
        }
        
        // Añadir los complementos (add-ons)
        foreach ($subscription->addOns as $addon) {
            // Verificar si hay un descuento específico para este add-on
            $quantity = $addon->pivot->quantity;
            $isDiscounted = false;
            
            if ($subscription->discount_id) {
                $discount = Discount::find($subscription->discount_id);
                if ($discount) {
                    $discountMeta = json_decode($discount->metadata ?: '{}', true);
                    
                    // Verificar si el descuento aplica a este add-on
                    if (isset($discountMeta['addon_code']) && $discountMeta['addon_code'] === $addon->code) {
                        $isDiscounted = true;
                        
                        // Si hay unidades gratuitas, ajustar la cantidad
                        if (isset($discountMeta['is_free']) && $discountMeta['is_free'] && 
                            isset($discountMeta['free_quantity']) && $discountMeta['free_quantity'] > 0) {
                            $freeQuantity = $discountMeta['free_quantity'];
                            $quantity = max(0, $quantity - $freeQuantity);
                            
                            // Si todas las unidades son gratuitas, omitir este item
                            if ($quantity <= 0) {
                                continue;
                            }
                        }
                    }
                }
            }
            
            // Solo añadir si hay una cantidad a cobrar
            if ($quantity > 0 && $addon->stripe_price_id) {
                $lineItems[] = [
                    'price' => $addon->stripe_price_id,
                    'quantity' => $quantity,
                ];
            }
        }
        
        return $lineItems;
    }
    
    /**
     * Obtener el código de país de la organización
     *
     * @param Organization $organization
     * @return string
     */
    protected function getCountryCode(Organization $organization): string
    {
        $countryCode = $organization->country_code;
        
        // Si no tiene country_code pero sí country_id, obtenerlo
        if (!$countryCode && $organization->country_id) {
            $country = Country::find($organization->country_id);
            if ($country) {
                $countryCode = $country->iso2;
            }
        }
        
        // Si aún no tiene country_code, usar US por defecto
        return $countryCode ?: 'US';
    }
    
    /**
     * Obtener el ID de precio en Stripe para un plan según el país y tipo de facturación
     *
     * @param Plan $plan
     * @param string $countryCode
     * @param string $billingInterval
     * @return string|null
     */
    protected function getStripePriceIdForPlan(Plan $plan, string $countryCode, string $billingInterval): ?string
    {
        // Mapear el intervalo de facturación al tipo de plan en la base de datos
        $planType = match($billingInterval) {
            'annual-once' => 'annual',
            'annual-monthly' => 'monthly_annual',
            default => 'monthly',
        };
        
        // Buscar el precio específico para este país y tipo de plan
        $planPrice = PlanPriceByCountry::where('plan_id', $plan->id)
            ->where('country_code', $countryCode)
            ->where('billing_interval', $planType)
            ->first();
        
        // Si no hay precio específico para este país, intentar con un precio general
        if (!$planPrice) {
            $planPrice = PlanPriceByCountry::where('plan_id', $plan->id)
                ->where('billing_interval', $planType)
                ->first();
        }
        
        // Si el precio tiene un ID específico para este tipo de facturación en sus metadatos
        if ($planPrice) {
            return $planPrice->getStripePriceIdForBilling($planType);
        }
        
        return null;
    }
    
    /**
     * Actualizar el estado de una suscripción después del pago
     *
     * @param string $checkoutSessionId
     * @return bool
     */
    public function handleSuccessfulPayment(string $checkoutSessionId): bool
    {
        try {
            // Obtener la sesión de checkout
            $session = $this->stripe->checkout->sessions->retrieve($checkoutSessionId);
            
            // Buscar la suscripción por ID en metadatos
            $subscriptionId = $session->metadata->subscription_id ?? null;
            if (!$subscriptionId) {
                throw new \Exception('ID de suscripción no encontrado en metadatos');
            }
            
            $subscription = Subscription::find($subscriptionId);
            if (!$subscription) {
                throw new \Exception('Suscripción no encontrada: ' . $subscriptionId);
            }
            
            // Actualizar la suscripción con la información de Stripe
            $subscription->stripe_id = $session->subscription;
            $subscription->stripe_status = 'active';
            $subscription->save();
            
            // Actualizar la organización
            $organization = $subscription->organization;
            if ($organization) {
                $organization->status = 'active';
                $organization->save();
            }
            
            return true;
        } catch (\Exception $e) {
            Log::error('Error al procesar pago exitoso: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Cambiar plan de suscripción
     *
     * @param Subscription $subscription
     * @param int $newPlanId
     * @param string $billingInterval
     * @return bool
     */
    public function changePlan(Subscription $subscription, int $newPlanId, string $billingInterval): bool
    {
        try {
            // Comprobar si la suscripción está activa y tiene un ID en Stripe
            if (!$subscription->stripe_id || $subscription->stripe_status !== 'active') {
                throw new \Exception('La suscripción no está activa en Stripe');
            }
            
            $organization = $subscription->organization;
            $countryCode = $this->getCountryCode($organization);
            
            // Obtener el nuevo plan
            $newPlan = Plan::find($newPlanId);
            if (!$newPlan) {
                throw new \Exception('Plan no encontrado');
            }
            
            // Obtener el nuevo precio en Stripe
            $newStripePriceId = $this->getStripePriceIdForPlan($newPlan, $countryCode, $billingInterval);
            if (!$newStripePriceId) {
                throw new \Exception('Precio no encontrado para el nuevo plan');
            }
            
            // Determinar si es upgrade o downgrade para calcular prorratas
            $currentPlan = $subscription->plan;
            $isUpgrade = false;
            
            if ($currentPlan) {
                $currentPrice = PlanPriceByCountry::where('plan_id', $currentPlan->id)
                    ->where('country_code', $countryCode)
                    ->first();
                    
                $newPrice = PlanPriceByCountry::where('plan_id', $newPlanId)
                    ->where('country_code', $countryCode)
                    ->first();
                    
                if ($currentPrice && $newPrice) {
                    $isUpgrade = $newPrice->price > $currentPrice->price;
                }
            }
            
            // Actualizar la suscripción en Stripe
            $stripeSubscription = $this->stripe->subscriptions->retrieve($subscription->stripe_id);
            
            // Encontrar el item del plan principal (normalmente el primero)
            $itemId = $stripeSubscription->items->data[0]->id;
            
            $this->stripe->subscriptions->update($subscription->stripe_id, [
                'items' => [
                    [
                        'id' => $itemId,
                        'price' => $newStripePriceId,
                    ],
                ],
                'proration_behavior' => $isUpgrade ? 'always_invoice' : 'none',
                'metadata' => [
                    'subscription_id' => $subscription->id,
                    'organization_id' => $organization->id,
                    'billing_interval' => $billingInterval,
                ],
            ]);
            
            // Actualizar la suscripción en la base de datos
            $subscription->plan_id = $newPlanId;
            $subscription->billing_interval = $billingInterval;
            $subscription->save();
            
            return true;
        } catch (\Exception $e) {
            Log::error('Error al cambiar plan: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Añadir o actualizar un complemento (add-on) a la suscripción
     *
     * @param Subscription $subscription
     * @param int $addonId
     * @param int $quantity
     * @return bool
     */
    public function updateAddon(Subscription $subscription, int $addonId, int $quantity): bool
    {
        try {
            // Comprobar si la suscripción está activa y tiene un ID en Stripe
            if (!$subscription->stripe_id || $subscription->stripe_status !== 'active') {
                throw new \Exception('La suscripción no está activa en Stripe');
            }
            
            // Obtener el add-on
            $addon = AddOn::find($addonId);
            if (!$addon) {
                throw new \Exception('Complemento no encontrado');
            }
            
            // Verificar si ya existe este add-on en la suscripción
            $existingAddon = $subscription->addOns()->where('addon_id', $addonId)->first();
            $stripeItemId = $existingAddon ? $existingAddon->pivot->stripe_item_id : null;
            
            if ($quantity <= 0 && $existingAddon) {
                // Eliminar el add-on
                if ($stripeItemId) {
                    // Eliminar de Stripe
                    $this->stripe->subscriptionItems->delete($stripeItemId);
                }
                
                // Eliminar de la base de datos
                $subscription->addOns()->detach($addonId);
                
                return true;
            }
            
            // Si estamos actualizando un add-on existente en Stripe
            if ($stripeItemId) {
                $this->stripe->subscriptionItems->update($stripeItemId, [
                    'quantity' => $quantity,
                ]);
            } else {
                // Si estamos añadiendo un nuevo add-on
                $item = $this->stripe->subscriptionItems->create([
                    'subscription' => $subscription->stripe_id,
                    'price' => $addon->stripe_price_id,
                    'quantity' => $quantity,
                    'metadata' => [
                        'addon_id' => $addonId,
                        'subscription_id' => $subscription->id,
                    ],
                ]);
                
                $stripeItemId = $item->id;
            }
            
            // Actualizar o crear en la base de datos
            if ($existingAddon) {
                $subscription->addOns()->updateExistingPivot($addonId, [
                    'quantity' => $quantity,
                    'stripe_item_id' => $stripeItemId,
                ]);
            } else {
                $subscription->addOns()->attach($addonId, [
                    'quantity' => $quantity,
                    'price' => $addon->price,
                    'stripe_item_id' => $stripeItemId,
                ]);
            }
            
            return true;
        } catch (\Exception $e) {
            Log::error('Error al actualizar complemento: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Cancelar una suscripción
     *
     * @param Subscription $subscription
     * @param bool $immediately Cancelar inmediatamente o al final del período actual
     * @return bool
     */
    public function cancelSubscription(Subscription $subscription, bool $immediately = false): bool
    {
        try {
            // Comprobar si la suscripción está activa y tiene un ID en Stripe
            if (!$subscription->stripe_id || $subscription->stripe_status !== 'active') {
                throw new \Exception('La suscripción no está activa en Stripe');
            }
            
            if ($immediately) {
                // Cancelar inmediatamente
                $this->stripe->subscriptions->cancel($subscription->stripe_id);
                
                // Actualizar en la base de datos
                $subscription->stripe_status = 'canceled';
                $subscription->ends_at = now();
                $subscription->save();
            } else {
                // Cancelar al final del período
                $this->stripe->subscriptions->update($subscription->stripe_id, [
                    'cancel_at_period_end' => true,
                ]);
                
                // Obtener la información de la suscripción para saber cuándo terminará
                $stripeSubscription = $this->stripe->subscriptions->retrieve($subscription->stripe_id);
                
                // Actualizar en la base de datos
                $subscription->ends_at = date('Y-m-d H:i:s', $stripeSubscription->current_period_end);
                $subscription->save();
            }
            
            return true;
        } catch (\Exception $e) {
            Log::error('Error al cancelar suscripción: ' . $e->getMessage());
            return false;
        }
    }
}