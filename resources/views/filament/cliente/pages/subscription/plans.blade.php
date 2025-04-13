@extends('layouts.client')

@section('title', 'Planes de Suscripción')

@section('content')
<div class="container mx-auto px-4 py-8">
    <div class="mb-6">
        <h1 class="text-2xl font-bold text-gray-800 dark:text-white">Planes de Suscripción</h1>
        <p class="text-gray-600 dark:text-gray-300">Elige el plan que mejor se adapte a tus necesidades</p>
    </div>

    @if($currentSubscription)
        <div class="bg-yellow-50 dark:bg-yellow-900 border-l-4 border-yellow-400 p-4 mb-8">
            <div class="flex">
                <div class="flex-shrink-0">
                    <svg class="h-5 w-5 text-yellow-400" viewBox="0 0 20 20" fill="currentColor">
                        <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/>
                    </svg>
                </div>
                <div class="ml-3">
                    <p class="text-sm text-yellow-700 dark:text-yellow-200">
                        @if($currentSubscription->ends_at && $currentSubscription->ends_at <= now())
                            Tu suscripción ha terminado. Por favor, selecciona un nuevo plan para continuar utilizando el servicio.
                        @elseif($currentSubscription->ends_at && $currentSubscription->ends_at > now())
                            Tu suscripción actual está programada para finalizar el {{ $currentSubscription->ends_at->format('d/m/Y') }}. Puedes seleccionar un nuevo plan que se activará al finalizar tu suscripción actual.
                        @else
                            Ya tienes una suscripción activa al plan <strong>{{ $currentSubscription->plan->name }}</strong>. Si seleccionas un nuevo plan, tu suscripción actual será reemplazada.
                        @endif
                    </p>
                </div>
            </div>
        </div>
    @endif

    <!-- Opciones de Ciclo de Facturación -->
    <div class="flex justify-center mb-8">
        <div class="inline-flex rounded-md shadow-sm" role="group">
            <button type="button" class="billing-toggle px-4 py-2 text-sm font-medium text-gray-900 bg-white border border-gray-200 rounded-l-lg hover:bg-gray-100 hover:text-primary-700 focus:z-10 focus:ring-2 focus:ring-primary-700 focus:text-primary-700 dark:bg-gray-700 dark:border-gray-600 dark:text-white dark:hover:text-white dark:hover:bg-gray-600 dark:focus:ring-primary-500 dark:focus:text-white active-billing" data-billing="monthly">
                Mensual
            </button>
            <button type="button" class="billing-toggle px-4 py-2 text-sm font-medium text-gray-900 bg-white border-t border-b border-gray-200 hover:bg-gray-100 hover:text-primary-700 focus:z-10 focus:ring-2 focus:ring-primary-700 focus:text-primary-700 dark:bg-gray-700 dark:border-gray-600 dark:text-white dark:hover:text-white dark:hover:bg-gray-600 dark:focus:ring-primary-500 dark:focus:text-white" data-billing="annual-monthly">
                Anual (pago mensual)
            </button>
            <button type="button" class="billing-toggle px-4 py-2 text-sm font-medium text-gray-900 bg-white border border-gray-200 rounded-r-lg hover:bg-gray-100 hover:text-primary-700 focus:z-10 focus:ring-2 focus:ring-primary-700 focus:text-primary-700 dark:bg-gray-700 dark:border-gray-600 dark:text-white dark:hover:text-white dark:hover:bg-gray-600 dark:focus:ring-primary-500 dark:focus:text-white" data-billing="annual-once">
                Anual (pago único)
            </button>
        </div>
    </div>

    <!-- Planes -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
        @foreach($plans as $plan)
            @php
                // Obtener precios para cada tipo de facturación
                $monthlyPrice = $plan->prices_by_country->where('billing_interval', 'monthly')->first();
                $monthlyAnnualPrice = $plan->prices_by_country->where('billing_interval', 'monthly_annual')->first();
                $annualPrice = $plan->prices_by_country->where('billing_interval', 'annual')->first();
                
                // Si no existen precios específicos, calcular basado en el precio base
                if (!$monthlyAnnualPrice && $monthlyPrice) {
                    $monthlyAnnualValue = $monthlyPrice->price * 0.85; // 15% descuento
                } else {
                    $monthlyAnnualValue = $monthlyAnnualPrice ? $monthlyAnnualPrice->price : 0;
                }
                
                if (!$annualPrice && $monthlyPrice) {
                    $annualValue = $monthlyPrice->price * 12 * 0.7; // 30% descuento
                } else {
                    $annualValue = $annualPrice ? $annualPrice->price : 0;
                }
                
                $currency = $monthlyPrice ? $monthlyPrice->currency : 'USD';
                
                // Para mostrar equivalente mensual del plan anual
                $annualMonthlyEquivalent = $annualValue / 12;
            @endphp

            <div class="bg-white dark:bg-gray-800 rounded-lg shadow-lg overflow-hidden">
                <div class="px-6 py-8 bg-indigo-600 dark:bg-indigo-800">
                    <h3 class="text-xl font-bold text-white text-center">{{ $plan->name }}</h3>
                </div>
                
                <div class="p-6 space-y-6">
                    <div class="text-center">
                        <div class="text-monthly">
                            <span class="text-4xl font-bold text-gray-900 dark:text-white">{{ $currency }} {{ number_format($monthlyPrice ? $monthlyPrice->price : 0, 2) }}</span>
                            <span class="text-gray-500 dark:text-gray-400">/mes</span>
                        </div>
                        <div class="text-annual-monthly hidden">
                            <span class="text-4xl font-bold text-gray-900 dark:text-white">{{ $currency }} {{ number_format($monthlyAnnualValue, 2) }}</span>
                            <span class="text-gray-500 dark:text-gray-400">/mes</span>
                            <div class="text-sm mt-1 text-green-600 dark:text-green-400">Ahorro del 15% con contrato anual</div>
                        </div>
                        <div class="text-annual-once hidden">
                            <span class="text-4xl font-bold text-gray-900 dark:text-white">{{ $currency }} {{ number_format($annualValue, 2) }}</span>
                            <span class="text-gray-500 dark:text-gray-400">/año</span>
                            <div class="text-sm mt-1 text-green-600 dark:text-green-400">
                                Equivalente a {{ $currency }} {{ number_format($annualMonthlyEquivalent, 2) }}/mes
                                <br>Ahorro del 30% con pago anual
                            </div>
                        </div>
                    </div>
                    
                    <div class="space-y-4">
                        <div class="flex items-start">
                            <svg class="flex-shrink-0 h-5 w-5 text-green-500 dark:text-green-400 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                            </svg>
                            <div class="ml-3 text-sm text-gray-500 dark:text-gray-400">{{ $plan->description }}</div>
                        </div>
                        
                        @foreach($plan->features as $feature)
                        <div class="flex items-start">
                            <svg class="flex-shrink-0 h-5 w-5 text-green-500 dark:text-green-400 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                            </svg>
                            <div class="ml-3 text-sm text-gray-500 dark:text-gray-400">
                                {{ $feature->name }}
                                @if($feature->pivot->value)
                                    : <span class="font-medium">{{ $feature->pivot->value }}</span>
                                @endif
                            </div>
                        </div>
                        @endforeach
                    </div>
                </div>
                
                <div class="px-6 py-4 bg-gray-50 dark:bg-gray-700 border-t border-gray-200 dark:border-gray-600">
                    <div class="text-monthly">
                        <a href="{{ route('client.subscription.create', $plan->id) }}?billing=monthly" class="block w-full px-4 py-2 text-center text-white bg-indigo-600 hover:bg-indigo-700 rounded-md">
                            Seleccionar Plan
                        </a>
                    </div>
                    <div class="text-annual-monthly hidden">
                        <a href="{{ route('client.subscription.create', $plan->id) }}?billing=annual-monthly" class="block w-full px-4 py-2 text-center text-white bg-indigo-600 hover:bg-indigo-700 rounded-md">
                            Seleccionar Plan
                        </a>
                    </div>
                    <div class="text-annual-once hidden">
                        <a href="{{ route('client.subscription.create', $plan->id) }}?billing=annual-once" class="block w-full px-4 py-2 text-center text-white bg-indigo-600 hover:bg-indigo-700 rounded-md">
                            Seleccionar Plan
                        </a>
                    </div>
                </div>
            </div>
        @endforeach
    </div>
</div>

@endsection

@push('scripts')
<script>
    document.addEventListener('DOMContentLoaded', function() {
        // Manejar el cambio entre opciones de facturación
        const billingToggles = document.querySelectorAll('.billing-toggle');
        const monthlyElements = document.querySelectorAll('.text-monthly');
        const annualMonthlyElements = document.querySelectorAll('.text-annual-monthly');
        const annualOnceElements = document.querySelectorAll('.text-annual-once');

        function updatePriceDisplay(billingType) {
            // Ocultar todos
            monthlyElements.forEach(el => el.classList.add('hidden'));
            annualMonthlyElements.forEach(el => el.classList.add('hidden'));
            annualOnceElements.forEach(el => el.classList.add('hidden'));
            
            // Mostrar el seleccionado
            if (billingType === 'monthly') {
                monthlyElements.forEach(el => el.classList.remove('hidden'));
            } else if (billingType === 'annual-monthly') {
                annualMonthlyElements.forEach(el => el.classList.remove('hidden'));
            } else if (billingType === 'annual-once') {
                annualOnceElements.forEach(el => el.classList.remove('hidden'));
            }
            
            // Actualizar clases de los botones
            billingToggles.forEach(btn => {
                btn.classList.remove('active-billing', 'bg-indigo-100', 'text-indigo-800', 'dark:bg-indigo-900', 'dark:text-indigo-300');
                if (btn.dataset.billing === billingType) {
                    btn.classList.add('active-billing', 'bg-indigo-100', 'text-indigo-800', 'dark:bg-indigo-900', 'dark:text-indigo-300');
                }
            });
        }

        billingToggles.forEach(toggle => {
            toggle.addEventListener('click', function() {
                const billingType = this.dataset.billing;
                updatePriceDisplay(billingType);
            });
        });

        // Inicializar con facturación mensual
        updatePriceDisplay('monthly');
    });
</script>
@endpush