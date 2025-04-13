@extends('layouts.client')

@section('title', 'Mi Suscripción')

@section('content')
<div class="container mx-auto px-4 py-8">
    <div class="mb-6">
        <h1 class="text-2xl font-bold text-gray-800 dark:text-white">Detalles de Suscripción</h1>
        <p class="text-gray-600 dark:text-gray-300">Gestiona tu suscripción y complementos</p>
    </div>

    <div class="bg-white dark:bg-gray-800 shadow rounded-lg overflow-hidden mb-8">
        <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700 flex justify-between items-center">
            <h2 class="text-xl font-semibold text-gray-800 dark:text-white">
                Plan: {{ $subscription->plan->name }}
            </h2>
            <span class="px-3 py-1 rounded-full text-sm font-medium
                @if($subscription->ends_at && $subscription->ends_at <= now())
                    bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200
                @else
                    Activa
                @endif
            </span>
        </div>

        <div class="p-6">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                <div class="space-y-4">
                    <div>
                        <h3 class="text-sm font-medium text-gray-500 dark:text-gray-400">Ciclo de Facturación</h3>
                        <p class="mt-1 text-base font-medium text-gray-900 dark:text-white">
                            {{ $subscription->billing_type }}
                        </p>
                    </div>
                    
                    <div>
                        <h3 class="text-sm font-medium text-gray-500 dark:text-gray-400">Fecha de Inicio</h3>
                        <p class="mt-1 text-base font-medium text-gray-900 dark:text-white">
                            {{ $subscription->starts_at ? $subscription->starts_at->format('d/m/Y') : 'N/A' }}
                        </p>
                    </div>
                    
                    @if($subscription->ends_at)
                    <div>
                        <h3 class="text-sm font-medium text-gray-500 dark:text-gray-400">Fecha de Finalización</h3>
                        <p class="mt-1 text-base font-medium text-gray-900 dark:text-white">
                            {{ $subscription->ends_at->format('d/m/Y') }}
                        </p>
                    </div>
                    @endif
                </div>
                
                <div class="space-y-4">
                    <div>
                        <h3 class="text-sm font-medium text-gray-500 dark:text-gray-400">Estado de Facturación</h3>
                        <p class="mt-1 text-base font-medium text-gray-900 dark:text-white">
                            @if($subscription->stripe_status)
                                {{ ucfirst($subscription->stripe_status) }}
                            @else
                                No disponible
                            @endif
                        </p>
                    </div>
                    
                    @if($subscription->discount)
                    <div>
                        <h3 class="text-sm font-medium text-gray-500 dark:text-gray-400">Descuento Aplicado</h3>
                        <p class="mt-1 text-base font-medium text-green-600 dark:text-green-400">
                            {{ $subscription->discount->code }}
                            ({{ $subscription->discount->type === 'percentage' ? $subscription->discount->value . '%' : 'if($subscription->ends_at && $subscription->ends_at > now())
                    bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200
                @else
                    bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200
                @endif
            ">
                @if($subscription->ends_at && $subscription->ends_at <= now())
                    Cancelada
                @elseif($subscription->ends_at && $subscription->ends_at > now())
                    Cancelada (Finaliza el {{ $subscription->ends_at->format('d/m/Y') }})
                @else . number_format($subscription->discount->value, 2) }})
                        </p>
                    </div>
                    @endif
                    
                    <div>
                        <h3 class="text-sm font-medium text-gray-500 dark:text-gray-400">Factura con Datos Fiscales</h3>
                        <p class="mt-1 text-base font-medium text-gray-900 dark:text-white">
                            {{ $subscription->is_taxable ? 'Sí' : 'No' }}
                        </p>
                    </div>
                </div>
                
                <div class="md:border-l md:border-gray-200 md:dark:border-gray-700 md:pl-6">
                    <h3 class="text-sm font-medium text-gray-500 dark:text-gray-400 mb-3">Limitaciones del Plan</h3>
                    
                    <div class="space-y-3">
                        <div class="flex items-center justify-between">
                            <span class="text-sm text-gray-700 dark:text-gray-300">Usuarios</span>
                            <span class="text-sm font-medium 
                                {{ $currentUserCount >= $userLimit && $userLimit > 0 ? 'text-red-600 dark:text-red-400' : 'text-gray-900 dark:text-white' }}">
                                {{ $currentUserCount }} / {{ $userLimit > 0 ? $userLimit : '∞' }}
                            </span>
                        </div>
                        
                        <div class="flex items-center justify-between">
                            <span class="text-sm text-gray-700 dark:text-gray-300">Países Monitoreados</span>
                            <span class="text-sm font-medium text-gray-900 dark:text-white">
                                {{ count($monitoredCountries) }} / {{ $countryLimit > 0 ? $countryLimit : '∞' }}
                            </span>
                        </div>
                        
                        <!-- Aquí se pueden añadir más limitaciones basadas en las características del plan -->
                    </div>
                </div>
            </div>
        </div>
        
        <div class="px-6 py-4 bg-gray-50 dark:bg-gray-700 border-t border-gray-200 dark:border-gray-700 flex justify-between">
            @if(!$subscription->ends_at)
                <div>
                    <a href="{{ route('client.subscription.edit-addons') }}" class="inline-flex items-center px-4 py-2 border border-gray-300 dark:border-gray-600 shadow-sm text-sm font-medium rounded-md text-gray-700 dark:text-gray-200 bg-white dark:bg-gray-800 hover:bg-gray-50 dark:hover:bg-gray-700">
                        <svg class="mr-2 -ml-1 h-5 w-5 text-gray-500 dark:text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
                        </svg>
                        Gestionar Complementos
                    </a>
                </div>
                
                <a href="{{ route('client.subscription.confirm-cancel') }}" class="inline-flex items-center px-4 py-2 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-red-600 hover:bg-red-700">
                    <svg class="mr-2 -ml-1 h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                    Cancelar Suscripción
                </a>
            @else
                <div></div>
                <a href="{{ route('client.subscription.plans') }}" class="inline-flex items-center px-4 py-2 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-indigo-600 hover:bg-indigo-700">
                    <svg class="mr-2 -ml-1 h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
                    </svg>
                    Elegir Nuevo Plan
                </a>
            @endif
        </div>
    </div>

    <!-- Sección de Complementos -->
    @if(count($currentAddOns) > 0)
    <div class="bg-white dark:bg-gray-800 shadow rounded-lg overflow-hidden mb-8">
        <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700">
            <h2 class="text-xl font-semibold text-gray-800 dark:text-white">Complementos Activos</h2>
        </div>
        
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                <thead class="bg-gray-50 dark:bg-gray-700">
                    <tr>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">
                            Complemento
                        </th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">
                            Cantidad
                        </th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">
                            Precio
                        </th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">
                            Estado
                        </th>
                    </tr>
                </thead>
                <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                    @foreach($currentAddOns as $addOn)
                    <tr>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <div class="flex items-center">
                                <div>
                                    <div class="text-sm font-medium text-gray-900 dark:text-white">
                                        {{ $addOn->name }}
                                    </div>
                                    <div class="text-sm text-gray-500 dark:text-gray-400">
                                        {{ $addOn->code }}
                                    </div>
                                </div>
                            </div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <div class="text-sm text-gray-900 dark:text-white">{{ $addOn->pivot->quantity }}</div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <div class="text-sm text-gray-900 dark:text-white">
                                {{ $addOn->pivot->currency }} {{ number_format($addOn->pivot->price, 2) }}
                            </div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            @php
                                $metadata = json_decode($addOn->metadata ?? '{}', true);
                                $isRemovable = !(isset($metadata['non_removable']) && $metadata['non_removable']);
                            @endphp
                            
                            @if($isRemovable)
                                <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200">
                                    Removible
                                </span>
                            @else
                                <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200">
                                    No Removible
                                </span>
                            @endif
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
    @endif

    <!-- Sección de Características del Plan -->
    <div class="bg-white dark:bg-gray-800 shadow rounded-lg overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700">
            <h2 class="text-xl font-semibold text-gray-800 dark:text-white">Características Incluidas</h2>
        </div>
        
        <div class="p-6">
            <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-6">
                @foreach($subscription->plan->features as $feature)
                <div class="flex items-start">
                    <svg class="h-5 w-5 text-green-500 dark:text-green-400 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                    </svg>
                    <div class="ml-3">
                        <h3 class="text-sm font-medium text-gray-900 dark:text-white">{{ $feature->name }}</h3>
                        @if($feature->pivot->value)
                            <p class="text-sm text-gray-500 dark:text-gray-400">{{ $feature->pivot->value }}</p>
                        @endif
                    </div>
                </div>
                @endforeach
            </div>
        </div>
    </div>
</div>