<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Models\Plan;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Models\Subscription;
use App\Services\StripeCheckoutService;
use Filament\Notifications\Notification;
use Stripe\Webhook;
use Stripe\Exception\SignatureVerificationException;
use Illuminate\Support\Facades\DB;
use Stripe\Stripe;

class StripeController extends Controller
{
    public function __construct()
    {
        // Establecer la clave de Stripe en el constructor
        Stripe::setApiKey(config('services.stripe.secret'));
    }

    public function handleWebhook(Request $request)
    {
        $payload = $request->getContent();
        $sigHeader = $request->header('Stripe-Signature');
        
        // Determinar el secret basado en el entorno
        $webhookSecret = app()->environment('production') 
            ? config('services.stripe.live_webhook_secret') 
            : config('services.stripe.test_webhook_secret');

        try {
            // Intentar construir el evento de Stripe
            $event = Webhook::constructEvent($payload, $sigHeader, $webhookSecret);
        } catch (\UnexpectedValueException $e) {
            // Payload inválido
            Log::error("Webhook Stripe error: invalid payload", [
                'error' => $e->getMessage(),
                'payload' => $payload
            ]);
            return response('Invalid payload', 400);
        } catch (SignatureVerificationException $e) {
            // Firma inválida
            Log::error("Webhook Stripe error: invalid signature", [
                'error' => $e->getMessage(),
                'signature' => $sigHeader
            ]);
            return response('Invalid signature', 400);
        }

        // Log del tipo de evento para depuración
        Log::info("Stripe Webhook received", [
            'event_type' => $event->type,
            'event_id' => $event->id
        ]);

        // Manejar diferentes tipos de eventos de Stripe
        try {
            $response = match ($event->type) {
                'checkout.session.completed' => $this->handleCheckoutCompleted($event->data->object),
                'customer.subscription.deleted' => $this->handleSubscriptionDeleted($event->data->object),
                'customer.subscription.updated' => $this->handleSubscriptionUpdated($event->data->object),
                default => $this->handleUnknownEvent($event->type)
            };

            return $response ?? response('Event processed', 200);
        } catch (\Exception $e) {
            // Capturar y registrar cualquier error durante el procesamiento del evento
            Log::error("Webhook processing error", [
                'event_type' => $event->type,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response('Processing error', 500);
        }
    }

    protected function handleCheckoutCompleted($session)
    {
        DB::beginTransaction();
        try {
            // Buscar suscripción por ID de sesión de Stripe
            $subscription = Subscription::where('stripe_session_id', $session->id)->firstOrFail();

            // Actualizar detalles de la suscripción
            $subscription->update([
                'status' => 'active',
                'stripe_subscription_id' => $session->subscription ?? null,
                'paid_at' => now(),
                'stripe_status' => 'active'
            ]);

            // Notificar a la organización
            $this->notifySubscriptionActivation($subscription);

            DB::commit();

            Log::info("Stripe: Subscription {$subscription->id} activated successfully.", [
                'subscription_id' => $subscription->id,
                'organization_id' => $subscription->organization_id
            ]);

            return response('Checkout completed', 200);

        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error("Stripe Checkout Completion Error", [
                'error' => $e->getMessage(),
                'session_id' => $session->id
            ]);

            return response('Processing error', 500);
        }
    }

    protected function handleSubscriptionDeleted($subscriptionData)
    {
        DB::beginTransaction();
        try {
            $subscription = Subscription::where('stripe_subscription_id', $subscriptionData->id)->first();

            if ($subscription) {
                $subscription->update([
                    'status' => 'cancelled',
                    'ends_at' => now()
                ]);

                // Notificar sobre cancelación
                $this->notifySubscriptionCancellation($subscription);
            }

            DB::commit();

            Log::info("Stripe: Subscription cancelled.", [
                'stripe_subscription_id' => $subscriptionData->id
            ]);

            return response('Subscription deleted', 200);

        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error("Stripe Subscription Deletion Error", [
                'error' => $e->getMessage(),
                'stripe_subscription_id' => $subscriptionData->id
            ]);

            return response('Processing error', 500);
        }
    }

    protected function handleSubscriptionUpdated($subscriptionData)
    {
        DB::beginTransaction();
        try {
            $subscription = Subscription::where('stripe_subscription_id', $subscriptionData->id)->first();

            if ($subscription) {
                // Mapear estados de Stripe a estados locales
                $statusMap = [
                    'active' => 'active',
                    'past_due' => 'past_due',
                    'canceled' => 'cancelled',
                    'unpaid' => 'unpaid',
                    'incomplete' => 'incomplete'
                ];

                $subscription->update([
                    'status' => $statusMap[$subscriptionData->status] ?? 'pending',
                    'stripe_status' => $subscriptionData->status
                ]);
            }

            DB::commit();

            Log::info("Stripe: Subscription updated.", [
                'stripe_subscription_id' => $subscriptionData->id,
                'new_status' => $subscription->status ?? 'not_found'
            ]);

            return response('Subscription updated', 200);

        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error("Stripe Subscription Update Error", [
                'error' => $e->getMessage(),
                'stripe_subscription_id' => $subscriptionData->id
            ]);

            return response('Processing error', 500);
        }
    }

    protected function handleUnknownEvent($eventType)
    {
        Log::info('Unhandled Stripe event: ' . $eventType);
        return response('Event not handled', 200);
    }

    protected function notifySubscriptionActivation(Subscription $subscription)
    {
        // Notificación para la organización
        if ($subscription->organization && $subscription->organization->user) {
            Notification::make()
                ->title('¡Suscripción Activada!')
                ->body("Tu suscripción al plan {$subscription->plan->name} ha sido activada con éxito.")
                ->success()
                ->sendToDatabase($subscription->organization->user);
        }
    }

    protected function notifySubscriptionCancellation(Subscription $subscription)
    {
        // Notificación de cancelación
        if ($subscription->organization && $subscription->organization->user) {
            Notification::make()
                ->title('Suscripción Cancelada')
                ->body("Tu suscripción al plan {$subscription->plan->name} ha sido cancelada.")
                ->warning()
                ->sendToDatabase($subscription->organization->user);
        }
    }

    public function initializeSubscription(Organization $organization, Plan $plan, $billingInterval = 'monthly')
    {
        DB::beginTransaction();
        try {
            // Crear suscripción en estado pendiente
            $subscription = Subscription::create([
                'organization_id' => $organization->id,
                'plan_id' => $plan->id,
                'status' => 'pending',
                'billing_interval' => $billingInterval,
                'starts_at' => now(),
                'type' => 'regular'
            ]);

            // Generar URL de checkout
            $stripeCheckoutService = new StripeCheckoutService();
            $checkoutUrl = $stripeCheckoutService->createCheckoutSession($subscription);

            DB::commit();

            // Redirigir al enlace de checkout de Stripe
            return redirect()->away($checkoutUrl);

        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('Error inicializando suscripción', [
                'organization_id' => $organization->id,
                'plan_id' => $plan->id,
                'error' => $e->getMessage()
            ]);

            // Notificación de error
            Notification::make()
                ->title('Error al iniciar suscripción')
                ->body('No se pudo generar el enlace de pago. Por favor, intenta nuevamente.')
                ->danger()
                ->send();

            return back()->with('error', 'No se pudo iniciar la suscripción. Inténtalo de nuevo.');
        }
    }
}