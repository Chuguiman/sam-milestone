<?php

use App\Models\Subscription;
use Illuminate\Support\Facades\Route;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Log;

Route::get('/', function () {
    return view('welcome');
});

Route::post('/webhooks/stripe', [App\Http\Controllers\StripeWebhookController::class, 'handleWebhook'])->name('webhooks.stripe');

Route::get('/subscription/{subscription}/success', function (Subscription $subscription) {
    // Registrar éxito
    Log::info("Suscripción completada exitosamente", [
        'subscription_id' => $subscription->id,
        'organization_id' => $subscription->organization_id
    ]);

    // Notificación en Filament
    Notification::make()
        ->title('Suscripción Completada')
        ->body("Tu suscripción ha sido activada correctamente.")
        ->success()
        ->sendToDatabase($subscription->organization->user);

    // Redirigir al index de suscripciones
    return redirect()->route('filament.admin.resources.subscriptions.index')
        ->with('success', 'Suscripción activada exitosamente');
})->name('subscription.success');

Route::get('/subscription/{subscription}/cancel', function (Subscription $subscription) {
    // Registrar cancelación
    Log::info("Suscripción cancelada", [
        'subscription_id' => $subscription->id,
        'organization_id' => $subscription->organization_id
    ]);

    // Actualizar estado de suscripción
    $subscription->update([
        'status' => 'cancelled',
        'stripe_status' => 'canceled'
    ]);

    // Notificación en Filament
    Notification::make()
        ->title('Suscripción Cancelada')
        ->body("Has cancelado el proceso de suscripción.")
        ->warning()
        ->sendToDatabase($subscription->organization->user);

    // Redirigir al index de suscripciones
    return redirect()->route('filament.admin.resources.subscriptions.index')
        ->with('warning', 'Proceso de suscripción cancelado');
})->name('subscription.cancel');

