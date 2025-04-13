<?php

namespace App\Services;

use App\Models\AddOn;
use App\Models\Feature;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionAddOn;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SubscriptionManagerService
{
    /**
     * Crea una nueva suscripción y la sincroniza con Stripe
     *
     * @param array $data Datos de la suscripción
     * @return Subscription|null
     */
    public function createSubscription(array $data)
    {
        DB::beginTransaction();
        try {
            // Crear la suscripción en la base de datos con campos correctos
            // Nota: NO incluimos 'name' aquí ya que no existe en tu tabla
            $subscription = Subscription::create([
                'user_id' => $data['user_id'],
                'organization_id' => $data['organization_id'],
                'plan_id' => $data['plan_id'],
                'type' => 'regular',
                'billing_interval' => $data['billing_interval'] ?? 'monthly',
                'is_taxable' => $data['is_taxable'] ?? false,
                'starts_at' => now(),
                'discount_id' => $data['discount_id'] ?? null,
                'stripe_status' => 'incomplete', // Estado inicial
            ]);

            // Procesar addons si existen
            if (isset($data['addon_selections']) && is_array($data['addon_selections'])) {
                foreach ($data['addon_selections'] as $addonId) {
                    $addon = AddOn::find($addonId);
                    if (!$addon) continue;

                    // Obtener la cantidad si se especificó
                    $quantity = $data['addon_quantities'][$addonId] ?? 1;

                    // Crear el registro de SubscriptionAddOn
                    $subscription->addOns()->attach($addon->id, [
                        'quantity' => $quantity,
                        'price' => $addon->price,
                        'currency' => $addon->currency ?? 'USD',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }

            // Sincronizar con Stripe si está configurado
            if (config('cashier.key')) {
                $this->syncWithStripe($subscription);
            }

            // Aplicar las limitaciones del plan
            $this->applyPlanLimitations($subscription);

            DB::commit();
            return $subscription;
        } catch (\Exception $e) {
            DB::rollback();
            Log::error('Error al crear suscripción: ' . $e->getMessage(), [
                'data' => $data,
                'trace' => $e->getTraceAsString()
            ]);
            return null;
        }
    }

    /**
     * Actualiza una suscripción existente
     *
     * @param Subscription $subscription
     * @param array $data
     * @return bool
     */
    public function updateSubscription(Subscription $subscription, array $data)
    {
        DB::beginTransaction();
        try {
            // Guardar estado anterior para comparar cambios
            $oldPlanId = $subscription->plan_id;
            $oldBillingInterval = $subscription->billing_interval;
            $oldDiscountId = $subscription->discount_id;

            // Actualizar la suscripción
            $subscription->update([
                'plan_id' => $data['plan_id'] ?? $subscription->plan_id,
                'billing_interval' => $data['billing_interval'] ?? $subscription->billing_interval,
                'is_taxable' => $data['is_taxable'] ?? $subscription->is_taxable,
                'discount_id' => $data['discount_id'] ?? $subscription->discount_id,
                'user_id' => $data['user_id'] ?? $subscription->user_id,
            ]);

            // Procesar addons
            if (isset($data['addon_selections'])) {
                // Eliminar addons que eran removibles y ya no están seleccionados
                $currentAddOns = $subscription->addOns()->get();
                foreach ($currentAddOns as $currentAddOn) {
                    // Verificar si el addon es removible
                    $metadata = $currentAddOn->metadata ? json_decode($currentAddOn->metadata, true) : [];
                    $isRemovable = !(isset($metadata['non_removable']) && $metadata['non_removable']);

                    if ($isRemovable && !in_array($currentAddOn->id, $data['addon_selections'])) {
                        $subscription->addOns()->detach($currentAddOn->id);
                    }
                }

                // Añadir nuevos addons o actualizar cantidades
                foreach ($data['addon_selections'] as $addonId) {
                    $addon = AddOn::find($addonId);
                    if (!$addon) continue;

                    $quantity = $data['addon_quantities'][$addonId] ?? 1;
                    $existingAddon = $subscription->addOns()->where('add_on_id', $addonId)->first();

                    if ($existingAddon) {
                        // Actualizar cantidad
                        $subscription->addOns()->updateExistingPivot($addonId, [
                            'quantity' => $quantity,
                            'updated_at' => now(),
                        ]);
                    } else {
                        // Añadir nuevo addon
                        $subscription->addOns()->attach($addonId, [
                            'quantity' => $quantity,
                            'price' => $addon->price,
                            'currency' => $addon->currency,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }
                }
            }

            // Verificar si necesitamos sincronizar con Stripe
            $needsStripeSync = $oldPlanId != $subscription->plan_id ||
                $oldBillingInterval != $subscription->billing_interval ||
                $oldDiscountId != $subscription->discount_id || 
                isset($data['addon_selections']);

            if ($needsStripeSync && config('cashier.key')) {
                $this->syncWithStripe($subscription);
            }

            // Aplicar nuevas limitaciones si el plan cambió
            if ($oldPlanId != $subscription->plan_id) {
                $this->applyPlanLimitations($subscription);
            }

            DB::commit();
            return true;
        } catch (\Exception $e) {
            DB::rollback();
            Log::error('Error al actualizar suscripción: ' . $e->getMessage(), [
                'subscription' => $subscription->id,
                'data' => $data,
                'trace' => $e->getTraceAsString()
            ]);
            return false;
        }
    }

    /**
     * Cancela una suscripción
     *
     * @param Subscription $subscription
     * @param bool $immediate Si es true, cancela inmediatamente, sino al final del período
     * @return bool
     */
    public function cancelSubscription(Subscription $subscription, bool $immediate = false)
    {
        try {
            if ($immediate) {
                $subscription->ends_at = now();
            } else {
                // Calcular la fecha de finalización según el intervalo de facturación
                // Esto es solo un ejemplo, ajusta según tu lógica de negocio
                if ($subscription->billing_interval === 'monthly') {
                    $subscription->ends_at = now()->addMonth();
                } elseif (in_array($subscription->billing_interval, ['annual-monthly', 'annual-once'])) {
                    $subscription->ends_at = now()->addYear();
                } else {
                    $subscription->ends_at = now()->addMonth();
                }
            }

            $subscription->save();

            // Cancelar en Stripe si está configurado
            if ($subscription->stripe_id && config('cashier.key')) {
                $stripe = new \Stripe\StripeClient(config('cashier.key'));
                $stripe->subscriptions->cancel($subscription->stripe_id, [
                    'cancel_at_period_end' => !$immediate,
                ]);
            }

            return true;
        } catch (\Exception $e) {
            Log::error('Error al cancelar suscripción: ' . $e->getMessage(), [
                'subscription' => $subscription->id,
                'trace' => $e->getTraceAsString()
            ]);
            return false;
        }
    }

    /**
     * Sincroniza una suscripción con Stripe
     *
     * @param Subscription $subscription
     * @return bool
     */
    public function syncWithStripe(Subscription $subscription)
    {
        try {
            $stripe = new \Stripe\StripeClient(config('cashier.key'));
            $organization = $subscription->organization;

            if (!$organization) {
                throw new \Exception("No se encontró la organización para la suscripción #{$subscription->id}");
            }

            // Crear o recuperar el cliente de Stripe
            $stripeCustomer = $this->getOrCreateStripeCustomer($organization);

            // Determinar el precio de Stripe basado en el plan y el intervalo de facturación
            $stripeItems = $this->getStripeItems($subscription);

            if (empty($stripeItems)) {
                throw new \Exception("No se pudieron determinar los items de Stripe para la suscripción");
            }

            // Preparar los datos de la suscripción
            $stripeData = [
                'customer' => $stripeCustomer->id,
                'items' => $stripeItems,
                'metadata' => [
                    'subscription_id' => $subscription->id,
                    'organization_id' => $organization->id,
                ],
            ];

            // Aplicar descuento si existe
            if ($subscription->discount_id) {
                $discount = $subscription->discount;
                if ($discount && $discount->stripe_coupon_id) {
                    $stripeData['coupon'] = $discount->stripe_coupon_id;
                }
            }

            // Crear o actualizar la suscripción en Stripe
            if ($subscription->stripe_id) {
                // Si ya existe, actualizar
                $stripeSubscription = $stripe->subscriptions->update(
                    $subscription->stripe_id,
                    $stripeData
                );
            } else {
                // Si no existe, crear nueva
                $stripeSubscription = $stripe->subscriptions->create($stripeData);
            }

            // Actualizar la suscripción local con datos de Stripe
            $subscription->update([
                'stripe_id' => $stripeSubscription->id,
                'stripe_status' => $stripeSubscription->status,
            ]);

            // Actualizar los IDs de Stripe de los items de la suscripción (add-ons)
            $this->updateStripeItemIds($subscription, $stripeSubscription);

            return true;
        } catch (\Exception $e) {
            Log::error('Error al sincronizar con Stripe: ' . $e->getMessage(), [
                'subscription' => $subscription->id,
                'trace' => $e->getTraceAsString()
            ]);
            return false;
        }
    }

    /**
     * Obtiene o crea un cliente en Stripe para una organización
     *
     * @param Organization $organization
     * @return \Stripe\Customer
     */
    protected function getOrCreateStripeCustomer(Organization $organization)
    {
        $stripe = new \Stripe\StripeClient(config('cashier.key'));

        // Si ya tiene un ID de Stripe, recuperarlo
        if ($organization->stripe_id) {
            try {
                return $stripe->customers->retrieve($organization->stripe_id);
            } catch (\Exception $e) {
                // Si hay un error (por ejemplo, el cliente ya no existe), crear uno nuevo
                Log::warning("Cliente Stripe no encontrado, creando uno nuevo: " . $e->getMessage());
            }
        }

        // Crear un nuevo cliente
        $owner = $organization->owner();
        $email = $owner ? $owner->email : ($organization->support_email ?? 'info@example.com');

        $customer = $stripe->customers->create([
            'name' => $organization->name,
            'email' => $email,
            'metadata' => [
                'organization_id' => $organization->id,
            ],
            'address' => [
                'line1' => $organization->address ?? '',
                'city' => $organization->city ? $organization->city->name : '',
                'postal_code' => $organization->postcode ?? '',
                'country' => $organization->country_code ?? 'US',
            ],
            'tax_id_data' => $organization->tax_id ? [
                [
                    'type' => 'eu_vat', // Adaptar según el tipo de identificación fiscal
                    'value' => $organization->tax_id,
                ]
            ] : [],
        ]);

        // Actualizar el ID de Stripe en la organización
        $organization->update(['stripe_id' => $customer->id]);

        return $customer;
    }

    /**
     * Obtiene los items para Stripe basados en la suscripción
     *
     * @param Subscription $subscription
     * @return array
     */
    protected function getStripeItems(Subscription $subscription)
    {
        $items = [];
        $plan = $subscription->plan;
        $organization = $subscription->organization;
        $billingInterval = $subscription->billing_interval;
        $countryCode = $organization->country_code ?? 'US';

        // Mapear el intervalo de facturación al formato que usa el modelo Plan
        $billingType = match ($billingInterval) {
            'annual-monthly' => 'monthly_annual',
            'annual-once' => 'annual',
            default => 'monthly'
        };

        // Obtener el stripe_price_id del plan
        $planPrice = $plan->getPriceForCountry($countryCode);
        if (!$planPrice) {
            Log::error("No se encontró precio para el país {$countryCode} del plan {$plan->id}");
            return [];
        }

        $stripePriceId = $planPrice->getStripePriceIdForBilling($billingType);
        if (!$stripePriceId) {
            Log::error("No se encontró stripe_price_id para el plan {$plan->id} con tipo de facturación {$billingType}");
            return [];
        }

        // Añadir el item del plan
        $items[] = [
            'price' => $stripePriceId,
            'quantity' => 1,
        ];

        // Añadir add-ons si existen
        foreach ($subscription->addOns as $addOn) {
            if (!$addOn->stripe_price_id) {
                Log::warning("Add-on {$addOn->id} no tiene stripe_price_id");
                continue;
            }

            $items[] = [
                'price' => $addOn->stripe_price_id,
                'quantity' => $addOn->pivot->quantity,
            ];
        }

        return $items;
    }

    /**
     * Actualiza los IDs de Stripe de los items de la suscripción
     *
     * @param Subscription $subscription
     * @param \Stripe\Subscription $stripeSubscription
     */
    protected function updateStripeItemIds(Subscription $subscription, $stripeSubscription)
    {
        // Obtener el item del plan
        $planItem = $stripeSubscription->items->data[0] ?? null;
        if ($planItem) {
            $subscription->update([
                'stripe_price' => $planItem->price->id,
            ]);
        }

        // Actualizar los add-ons
        $addonItems = array_slice($stripeSubscription->items->data, 1);
        foreach ($addonItems as $item) {
            // Buscar el add-on correspondiente
            $addOn = AddOn::where('stripe_price_id', $item->price->id)->first();
            if ($addOn) {
                SubscriptionAddOn::where('subscription_id', $subscription->id)
                    ->where('add_on_id', $addOn->id)
                    ->update(['stripe_item_id' => $item->id]);
            }
        }
    }

    /**
     * Aplica las limitaciones del plan a la organización
     *
     * @param Subscription $subscription
     */
    public function applyPlanLimitations(Subscription $subscription)
    {
        $plan = $subscription->plan;
        $organization = $subscription->organization;

        if (!$plan || !$organization) {
            return;
        }

        // Obtener todas las características del plan
        $features = $plan->features;

        foreach ($features as $feature) {
            $code = $feature->code;
            $value = $feature->pivot->value;

            // Aplicar limitación según el código de la característica
            switch ($code) {
                case 'max_users':
                    $this->applyMaxUsersLimit($organization, (int) $value);
                    break;
                case 'max_countries':
                    $this->applyMaxCountriesLimit($organization, (int) $value);
                    break;
                // Añadir más casos según las características/limitaciones que necesites
            }
        }

        // Guardar un registro de las limitaciones aplicadas
        $this->logLimitationChanges($subscription, $features);
    }

    /**
     * Aplica la limitación de usuarios máximos
     *
     * @param Organization $organization
     * @param int $maxUsers
     */
    protected function applyMaxUsersLimit(Organization $organization, int $maxUsers)
    {
        // Guardar el límite en los metadatos de la organización
        $metadata = json_decode($organization->metadata ?? '{}', true);
        $metadata['limits']['max_users'] = $maxUsers;
        $organization->metadata = json_encode($metadata);
        $organization->save();

        // Si hay más usuarios que el límite, desactivar los excedentes
        // Esta es una implementación de ejemplo, puedes adaptarla según tus necesidades
        $currentUsers = $organization->members()->count();
        
        if ($currentUsers > $maxUsers && $maxUsers > 0) {
            // Obtener todos los usuarios excepto los administradores y el propietario
            $regularUsers = $organization->members()
                ->wherePivotNotIn('role', ['admin', 'owner'])
                ->orderBy('organization_user.created_at', 'desc')
                ->get();

            $usersToRemove = $regularUsers->take($currentUsers - $maxUsers);
            
            foreach ($usersToRemove as $user) {
                // En lugar de eliminar, podrías marcarlos como inactivos
                $organization->members()->detach($user->id);
                
                // Notificar al usuario (implementación depende de tu sistema)
                // event(new UserRemovedFromOrganization($user, $organization));
            }
        }
    }

    /**
     * Aplica la limitación de países máximos
     *
     * @param Organization $organization
     * @param int $maxCountries
     */
    protected function applyMaxCountriesLimit(Organization $organization, int $maxCountries)
    {
        // Guardar el límite en los metadatos de la organización
        $metadata = json_decode($organization->metadata ?? '{}', true);
        $metadata['limits']['max_countries'] = $maxCountries;
        $organization->metadata = json_encode($metadata);
        $organization->save();

        // Implementar la limitación de países monitoreados
        if ($maxCountries > 0) {
            $currentCountries = $organization->activeMonitoredCountries()->count();
            
            if ($currentCountries > $maxCountries) {
                // Mantener solo los primeros $maxCountries países (por fecha de creación)
                $keepCountries = $organization->activeMonitoredCountries()
                    ->orderBy('created_at', 'asc')
                    ->limit($maxCountries)
                    ->pluck('country_id')
                    ->toArray();
                
                // Desactivar los países que exceden el límite
                $organization->monitoredCountries()
                    ->where('is_active', true)
                    ->whereNotIn('country_id', $keepCountries)
                    ->update(['is_active' => false]);
            }
        }
    }

    /**
     * Registra los cambios en las limitaciones
     *
     * @param Subscription $subscription
     * @param \Illuminate\Database\Eloquent\Collection $features
     */
    protected function logLimitationChanges(Subscription $subscription, $features)
    {
        $limitsLog = [];
        
        foreach ($features as $feature) {
            if (strpos($feature->code, 'max_') === 0) {
                $limitsLog[$feature->code] = [
                    'name' => $feature->name,
                    'value' => $feature->pivot->value,
                    'applied_at' => now()->toDateTimeString(),
                ];
            }
        }
        
        // Guardar el registro en los metadatos de la suscripción
        $metadata = json_decode($subscription->metadata ?? '{}', true);
        $metadata['applied_limits'] = $limitsLog;
        $subscription->metadata = json_encode($metadata);
        $subscription->save();
        
        // También podrías registrar esto en una tabla de logs o eventos
        // event(new SubscriptionLimitsApplied($subscription, $limitsLog));
    }

    /**
     * Verifica si una organización ha alcanzado su límite de usuarios
     *
     * @param Organization $organization
     * @return bool
     */
    public function hasReachedUserLimit(Organization $organization)
    {
        $metadata = json_decode($organization->metadata ?? '{}', true);
        $maxUsers = $metadata['limits']['max_users'] ?? 0;
        
        if ($maxUsers <= 0) {
            return false; // Sin límite
        }
        
        $currentUsers = $organization->members()->count();
        return $currentUsers >= $maxUsers;
    }


    /**
     * Obtiene el límite de usuarios para una organización
     *
     * @param Organization $organization
     * @return int
     */
    public function getUserLimit(Organization $organization)
    {
        $metadata = json_decode($organization->metadata ?? '{}', true);
        return $metadata['limits']['max_users'] ?? 0;
    }

    /**
     * Verifica si una organización ha alcanzado su límite de países
     *
     * @param Organization $organization
     * @return bool
     */
    public function hasReachedCountryLimit(Organization $organization)
    {
        return $organization->hasReachedMonitoredCountriesLimit();
    }

    // Y este método para obtener el límite actual

    /**
     * Obtiene el límite de países para una organización
     *
     * @param Organization $organization
     * @return int
     */
    public function getCountryLimit(Organization $organization)
    {
        return $organization->getMonitoredCountriesLimit();
    }

}