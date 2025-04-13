<x-filament::page>
    @php
        $subscription = $this->getSubscription();
        $features = $this->getPlanFeatures();
        $planPrice = $this->getPlanPrice();
    @endphp

    <x-filament::section class="border border-primary-500 rounded-lg overflow-hidden">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <!-- Columna izquierda: Información del plan -->
            <div>
                <h2 class="text-2xl font-bold">
                    {{ $subscription && $subscription->plan ? $subscription->plan->name : 'Essencial' }} Membership
                </h2>
                
                <p class="mt-2 text-sm text-gray-500">
                    La suscripción {{ $subscription && $subscription->plan ? $subscription->plan->name : 'Essencial' }}. A continuación se detallan las características y beneficios
                    de tu plan actual. Si tienes alguna duda, no dudes en contactarnos.
                </p>
                <br>
                <div class="mt-4">
                    <div class="flex items-center">
                        <h4 class="text-sm font-semibold text-primary-500 uppercase tracking-wider">Lo que incluye</h4>
                        <div class="ml-4 flex-grow border-t border-gray-200 dark:border-gray-700"></div>
                        
                    </div>
                    <br>

                    <div class="mt-4 grid grid-cols-1 md:grid-cols-2 gap-3">
                        @foreach($features as $feature)
                            <div class="flex items-start">
                                <div class="flex-shrink-0">
                                    <svg class="h-5 w-5 text-amber-500" fill="currentColor" viewBox="0 0 20 20">
                                        <circle cx="10" cy="10" r="10" opacity="0.2" />
                                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
                                    </svg>
                                </div>
                                
                                <p class="ml-3 text-sm text-gray-300">
                                    {{ $feature['name'] }}
                                    @if($feature['value'] && !is_bool($feature['value']))
                                        <span class="font-medium text-primary-500">{{ $feature['value'] }}</span>
                                    @endif
                                </p>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
            
            <!-- Columna derecha: Precio y acciones -->
            <div class="bg-gray-100 dark:bg-gray-800 rounded-lg p-6 flex flex-col items-center justify-center">
                <h3 class="text-base font-medium">
                    @if($planPrice)
                        Facturación {{ $planPrice['billing_type'] }} 

                    @else
                        Plan sin precio definido
                    @endif
                </h3>
                
                @if($planPrice)
                    <div class="mt-4 flex items-center justify-center">
                        <h1 class="text-5xl md:text-5xl font-extrabold">${{ number_format($planPrice['price'], 0) }}</h1>
                        <span class="ml-2 text-xl text-gray-500 dark:text-gray-400">
                            /{{ in_array($planPrice['billing_interval'], ['annual', 'annual-once']) ? 'año' : 'mes' }}
                        </span>
                    </div>
                @endif
                <br>
                <div class="mt-8 w-full">
                    <x-filament::button
                        color="warning"
                        class="w-full justify-center"
                        disabled
                    >
                        Tu plan actual
                    </x-filament::button>
                    
                    <x-filament::button
                        color="gray"
                        class="w-full justify-center mt-3"
                        tag="a" 
                        href="{{-- {{ route('filament.cliente.pages.subscription.plans') }} --}}"
                    >
                        Cambiar plan
                    </x-filament::button>
                </div>
            </div>
        </div>
    </x-filament::section>
    
    <!-- Información de diagnóstico -->
{{--     <x-filament::section>
        <x-slot name="heading">Información de Diagnóstico</x-slot>
        
        <pre class="text-xs overflow-auto">
            Tenant ID: {{ $this->getTenant()?->id ?? 'No tenant' }}
            User ID: {{ auth()->id() }}
            User Email: {{ auth()->user()->email }}
            País usado para precio: {{ $this->getCountryCode() }}
            Subscription: {{ $subscription ? "ID: {$subscription->id}, Status: {$subscription->stripe_status}" : 'No subscription' }}
            Plan actual: {{ $subscription?->plan?->name ?? 'Ninguno' }}
            Billing Interval (Suscripción): {{ $subscription->billing_interval ?? 'N/A' }}
            Precio encontrado: {{ $planPrice ? "Sí" : "No" }}
            Billing Interval (Precio): {{ $planPrice['billing_interval'] ?? 'N/A' }}
            Precio: ${{ $planPrice['price'] ?? 0 }} {{ $planPrice['currency'] ?? '' }}
        </pre>
    </x-filament::section> --}}
</x-filament::page>