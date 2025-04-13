@extends('layouts.client')

@section('title', 'Gestionar Complementos')

@section('content')
<div class="container mx-auto px-4 py-8">
    <div class="mb-6">
        <h1 class="text-2xl font-bold text-gray-800 dark:text-white">Gestionar Complementos</h1>
        <p class="text-gray-600 dark:text-gray-300">Añade o elimina complementos de tu suscripción</p>
    </div>

    <form action="{{ route('client.subscription.update-addons') }}" method="POST">
        @csrf
        <input type="hidden" name="subscription_id" value="{{ $subscription->id }}">
        
        <div class="mb-8">
            <div class="bg-white dark:bg-gray-800 shadow rounded-lg overflow-hidden">
                <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700">
                    <h2 class="text-xl font-semibold text-gray-800 dark:text-white">Complementos Actuales</h2>
                </div>
                
                <div class="p-6 space-y-6">
                    @if(count($currentAddOns) > 0)
                        <div class="grid grid-cols-1 gap-6">
                            @foreach($currentAddOns as $addOn)
                                @php
                                    $metadata = json_decode($addOn->metadata ?? '{}', true);
                                    $isRemovable = !(isset($metadata['non_removable']) && $metadata['non_removable']);
                                    $supportsQuantity = isset($metadata['supports_quantity']) && $metadata['supports_quantity'];
                                    $minQuantity = isset($metadata['min_quantity']) ? $metadata['min_quantity'] : 1;
                                    $maxQuantity = isset($metadata['max_quantity']) ? $metadata['max_quantity'] : 100;
                                @endphp
                                
                                <div class="flex items-start space-x-4 p-4 border border-gray-200 dark:border-gray-700 rounded-lg {{ $isRemovable ? '' : 'bg-yellow-50 dark:bg-yellow-900/30' }}">
                                    <div class="flex-1">
                                        <div class="flex items-center">
                                            @if($isRemovable)
                                                <input type="checkbox" name="addon_selections[]" value="{{ $addOn->id }}" 
                                                    id="addon-{{ $addOn->id }}" class="h-4 w-4 text-indigo-600 dark:text-indigo-500 border-gray-300 dark:border-gray-600 rounded focus:ring-indigo-500" 
                                                    checked>
                                            @else
                                                <input type="hidden" name="addon_selections[]" value="{{ $addOn->id }}">
                                                <div class="h-4 w-4 border-2 border-yellow-500 dark:border-yellow-600 rounded-sm bg-yellow-200 dark:bg-yellow-800"></div>
                                            @endif
                                            
                                            <label for="addon-{{ $addOn->id }}" class="ml-3 flex flex-col">
                                                <span class="text-sm font-medium text-gray-900 dark:text-white">{{ $addOn->name }}</span>
                                                <span class="text-sm text-gray-500 dark:text-gray-400">{{ $addOn->description }}</span>
                                            </label>
                                        </div>
                                        
                                        @if(!$isRemovable)
                                            <div class="mt-2 text-xs text-yellow-800 dark:text-yellow-200">
                                                <svg class="inline-block h-4 w-4 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path>
                                                </svg>
                                                Este complemento no puede ser removido una vez añadido.
                                            </div>
                                        @endif
                                    </div>
                                    
                                    <div class="flex items-center space-x-4">
                                        @if($supportsQuantity)
                                            <div class="flex items-center">
                                                <label for="addon-qty-{{ $addOn->id }}" class="sr-only">Cantidad</label>
                                                <input type="number" name="addon_quantities[{{ $addOn->id }}]" id="addon-qty-{{ $addOn->id }}" 
                                                    class="block w-16 rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                                                    min="{{ $minQuantity }}" max="{{ $maxQuantity }}" value="{{ $addOn->pivot->quantity }}">
                                            </div>
                                        @else
                                            <input type="hidden" name="addon_quantities[{{ $addOn->id }}]" value="1">
                                        @endif
                                        
                                        <div class="text-sm font-medium text-gray-900 dark:text-white">
                                            {{ $addOn->pivot->currency }} {{ number_format($addOn->pivot->price, 2) }}
                                            @if($supportsQuantity)
                                                / unidad
                                            @endif
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @else
                        <div class="text-center py-4">
                            <p class="text-gray-500 dark:text-gray-400">No tienes complementos activos actualmente.</p>
                        </div>
                    @endif
                </div>
            </div>
        </div>
        
        @if(count($availableAddOns) > 0)
        <div class="mb-8">
            <div class="bg-white dark:bg-gray-800 shadow rounded-lg overflow-hidden">
                <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700">
                    <h2 class="text-xl font-semibold text-gray-800 dark:text-white">Complementos Disponibles</h2>
                </div>
                
                <div class="p-6 space-y-6">
                    <div class="grid grid-cols-1 gap-6">
                        @foreach($availableAddOns as $addOn)
                            @php
                                $metadata = json_decode($addOn->metadata ?? '{}', true);
                                $isRemovable = !(isset($metadata['non_removable']) && $metadata['non_removable']);
                                $supportsQuantity = isset($metadata['supports_quantity']) && $metadata['supports_quantity'];
                                $minQuantity = isset($metadata['min_quantity']) ? $metadata['min_quantity'] : 1;
                                $maxQuantity = isset($metadata['max_quantity']) ? $metadata['max_quantity'] : 100;
                            @endphp
                            
                            <div class="flex items-start space-x-4 p-4 border border-gray-200 dark:border-gray-700 rounded-lg {{ $isRemovable ? '' : 'bg-yellow-50 dark:bg-yellow-900/30' }}">
                                <div class="flex-1">
                                    <div class="flex items-center">
                                        <input type="checkbox" name="addon_selections[]" value="{{ $addOn->id }}" 
                                            id="addon-{{ $addOn->id }}" class="h-4 w-4 text-indigo-600 dark:text-indigo-500 border-gray-300 dark:border-gray-600 rounded focus:ring-indigo-500">
                                        
                                        <label for="addon-{{ $addOn->id }}" class="ml-3 flex flex-col">
                                            <span class="text-sm font-medium text-gray-900 dark:text-white">{{ $addOn->name }}</span>
                                            <span class="text-sm text-gray-500 dark:text-gray-400">{{ $addOn->description }}</span>
                                        </label>
                                    </div>
                                    
                                    @if(!$isRemovable)
                                        <div class="mt-2 text-xs text-yellow-800 dark:text-yellow-200">
                                            <svg class="inline-block h-4 w-4 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path>
                                            </svg>
                                            Este complemento no podrá ser removido una vez añadido.
                                        </div>
                                    @endif
                                </div>
                                
                                <div class="flex items-center space-x-4">
                                    @if($supportsQuantity)
                                        <div class="flex items-center">
                                            <label for="addon-qty-{{ $addOn->id }}" class="sr-only">Cantidad</label>
                                            <input type="number" name="addon_quantities[{{ $addOn->id }}]" id="addon-qty-{{ $addOn->id }}" 
                                                class="block w-16 rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                                                min="{{ $minQuantity }}" max="{{ $maxQuantity }}" value="{{ $minQuantity }}">
                                        </div>
                                    @else
                                        <input type="hidden" name="addon_quantities[{{ $addOn->id }}]" value="1">
                                    @endif
                                    
                                    <div class="text-sm font-medium text-gray-900 dark:text-white">
                                        {{ $addOn->currency }} {{ number_format($addOn->price, 2) }}
                                        @if($supportsQuantity)
                                            / unidad
                                        @endif
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>
        @endif
        
        <div class="flex justify-between">
            <a href="{{ route('client.subscription.show') }}" class="inline-flex items-center px-4 py-2 border border-gray-300 dark:border-gray-600 shadow-sm text-sm font-medium rounded-md text-gray-700 dark:text-gray-200 bg-white dark:bg-gray-800 hover:bg-gray-50 dark:hover:bg-gray-700">
                <svg class="mr-2 -ml-1 h-5 w-5 text-gray-500 dark:text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path>
                </svg>
                Cancelar
            </a>
            
            <button type="submit" class="inline-flex items-center px-4 py-2 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-indigo-600 hover:bg-indigo-700">
                <svg class="mr-2 -ml-1 h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                </svg>
                Guardar Cambios
            </button>
        </div>
    </form>
</div>
@endsection