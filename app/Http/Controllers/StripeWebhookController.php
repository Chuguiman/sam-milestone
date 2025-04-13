<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Subscription;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Stripe\Event;
use Stripe\Stripe;
use Stripe\Webhook;

class StripeWebhookController extends Controller
{
    public function handleWebhook(Request $request)
    {
        Log::info('Webhook Stripe recibido', ['payload' => $request->all()]);
        
        $stripeKey = config('services.stripe.test_secret'); // O 'services.stripe.secret' en producción
        $webhookSecret = config('services.stripe.test_webhook_secret'); // O 'services.stripe.webhook_secret' en producción
        
        if (empty($stripeKey)) {
            Log::error('Clave API de Stripe no configurada');
            return response()->json(['error' => 'Configuration error'], 500);
        }
        
        Stripe::setApiKey($stripeKey);
        
        try {
            // Si estás en modo de prueba local, puedes omitir la verificación de firma
            // Para producción, siempre debes verificar la firma
            if (app()->environment('production')) {
                $event = Webhook::constructEvent(
                    $request->getContent(),
                    $request->header('Stripe-Signature'),
                    $webhookSecret
                );
            } else {
                // En desarrollo local, confía en los datos recibidos
                $event = json_decode($request->getContent(), true);
                $event = Event::constructFrom($event);
            }
            
            // Manejar diferentes eventos
            switch ($event->type) {
                case 'checkout.session.completed':
                    return $this->handleCheckoutCompleted($event->data->object);
                    
                case 'invoice.paid':
                    return $this->handleInvoicePaid($event->data->object);
                    
                case 'invoice.payment_failed':
                    return $this->handlePaymentFailed($event->data->object);
                    
                default:
                    Log::info('Evento de Stripe no manejado: ' . $event->type);
                    return response()->json(['status' => 'success']);
            }
            
        } catch (\Exception $e) {
            Log::error('Error en Stripe Webhook: ' . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }
    
    private function handleCheckoutCompleted($session)
    {
        Log::info('Checkout completado', ['session' => $session]);
        
        // Buscar orden por session_id
        $order = Order::where('stripe_session_id', $session->id)->first();
        
        if ($order) {
            // Actualizar la orden
            $order->update([
                'status' => 'paid',
                'paid_at' => now(),
            ]);
            
            // Si la orden tiene una suscripción asociada, actualizarla también
            if ($order->subscription) {
                $order->subscription->update([
                    'status' => 'active',
                    'stripe_status' => 'active',
                    'stripe_id' => $session->subscription,
                    'paid_at' => now(),
                    'starts_at' => now(),
                ]);
            }
            
            Log::info('Orden actualizada correctamente', ['order_id' => $order->id]);
        } else {
            Log::warning('No se encontró orden para la sesión', ['session_id' => $session->id]);
        }
        
        return response()->json(['status' => 'success']);
    }
    
    private function handleInvoicePaid($invoice)
    {
        Log::info('Factura pagada', ['invoice' => $invoice]);
        
        // Encontrar la suscripción por su ID de Stripe
        $subscription = Subscription::where('stripe_id', $invoice->subscription)->first();
        
        if ($subscription) {
            // Crear una nueva orden pagada
            $order = Order::create([
                'subscription_id' => $subscription->id,
                'organization_id' => $subscription->organization_id,
                'plan_id' => $subscription->plan_id,
                'status' => 'paid',
                'subtotal' => $invoice->amount_due / 100,
                'discount' => 0,
                'tax' => 0,
                'total_amount' => $invoice->amount_due / 100,
                'currency' => strtoupper($invoice->currency),
                'stripe_payment_intent_id' => $invoice->payment_intent,
                'billing_interval' => $subscription->billing_interval,
                'paid_at' => now(),
            ]);
            
            // Actualizar período de la suscripción
            if (isset($invoice->lines->data[0]->period)) {
                $subscription->update([
                    'starts_at' => now(),
                    'ends_at' => now()->addSeconds(
                        $invoice->lines->data[0]->period->end - $invoice->lines->data[0]->period->start
                    ),
                ]);
            }
            
            Log::info('Orden creada por factura pagada', ['order_id' => $order->id]);
        } else {
            Log::warning('No se encontró suscripción para la factura', ['subscription_id' => $invoice->subscription]);
        }
        
        return response()->json(['status' => 'success']);
    }
    
    private function handlePaymentFailed($invoice)
    {
        Log::info('Pago fallido', ['invoice' => $invoice]);
        
        // Encontrar la suscripción por su ID de Stripe
        $subscription = Subscription::where('stripe_id', $invoice->subscription)->first();
        
        if ($subscription) {
            // Crear una orden fallida
            $order = Order::create([
                'subscription_id' => $subscription->id,
                'organization_id' => $subscription->organization_id,
                'plan_id' => $subscription->plan_id,
                'status' => 'failed',
                'subtotal' => $invoice->amount_due / 100,
                'discount' => 0,
                'tax' => 0,
                'total_amount' => $invoice->amount_due / 100,
                'currency' => strtoupper($invoice->currency),
                'stripe_payment_intent_id' => $invoice->payment_intent,
                'billing_interval' => $subscription->billing_interval,
                'failed_reason' => $invoice->last_payment_error ? $invoice->last_payment_error->message : 'Pago fallido',
            ]);
            
            // Actualizar estado de la suscripción
            $subscription->update([
                'stripe_status' => 'past_due'
            ]);
            
            Log::info('Orden creada por pago fallido', ['order_id' => $order->id]);
        } else {
            Log::warning('No se encontró suscripción para el pago fallido', ['subscription_id' => $invoice->subscription]);
        }
        
        return response()->json(['status' => 'success']);
    }
}