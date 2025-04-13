<?php

namespace App\Filament\Resources;

use Andreia\FilamentStripePaymentLink\Forms\Actions\GenerateStripeLinkAction;
use App\Filament\Resources\SubscriptionResource\Pages;
use App\Filament\Resources\SubscriptionResource\RelationManagers;
use App\Models\Discount;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Country;
use App\Models\Subscription;
use App\Models\AddOn;
use App\Notifications\SubscriptionActivated;
use App\Services\StripeCheckoutService;
use Filament\Forms;
use Filament\Forms\Components\Tabs\Tab;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Nnjeim\World\Models\Currency;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class SubscriptionResource extends Resource
{
    protected static ?string $model = Subscription::class;

    protected static ?string $navigationIcon = 'heroicon-o-credit-card';

    protected static ?string $navigationGroup = 'Suscripciones';
    
    protected static ?int $navigationSort = 5;
    
    protected static ?string $recordTitleAttribute = 'id';
    
    protected static ?string $modelLabel = 'Suscripción';
    
    protected static ?string $pluralModelLabel = 'Suscripciones';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                // Primera sección: Cliente/Organización
                Forms\Components\Section::make('Datos del cliente')
                ->schema([
                    Forms\Components\Grid::make(3)
                        ->schema([
                            Forms\Components\Section::make()
                                ->schema([
                                    Forms\Components\Select::make('organization_id')
                                        ->label('Organización')
                                        ->relationship('organization', 'name')
                                        ->searchable()
                                        ->preload()
                                        ->required()
                                        ->live()
                                        ->afterStateUpdated(function (Forms\Set $set) {
                                            // Limpiar el usuario seleccionado cuando cambia la organización
                                            $set('user_id', null);
                                        }),
                                ])
                                ->columnSpan(1),

                            Forms\Components\Section::make()
                                ->schema([
                                    Forms\Components\Select::make('user_id')
                                        ->label('Usuario administrador')
                                        ->options(function (Forms\Get $get) {
                                            $organizationId = $get('organization_id');
                                            if (!$organizationId) return [];
                                            
                                            $organization = Organization::find($organizationId);
                                            if (!$organization) return [];
                                            
                                            return $organization->members()
                                                ->select(['users.id', 'users.name'])
                                                ->orderBy('users.name')
                                                ->pluck('name', 'id')
                                                ->toArray();
                                        })
                                        ->searchable()
                                        ->required()
                                        ->visible(fn (Forms\Get $get) => (bool) $get('organization_id')),
                                ])
                                ->columnSpan(1),

                            Forms\Components\Section::make()
                                ->schema([
                                    Forms\Components\Placeholder::make('country_info')
                                        ->label('País de facturación')
                                        ->content(function (Forms\Get $get) {
                                            $organizationId = $get('organization_id');
                                            if (!$organizationId) return 'Selecciona una organización primero';
                                            
                                            $organization = Organization::find($organizationId);
                                            if (!$organization) return 'Organización no encontrada';
                                            
                                            $countryCode = $organization->country_code;
                                            $countryName = null;
                                            
                                            // Si country_code es NULL pero country_id está presente, obtener el país
                                            if ($organization->country_id) {
                                                $country = Country::find($organization->country_id);
                                                if ($country) {
                                                    $countryName = $country->name;
                                                    $countryCode = $countryCode ?: $country->iso2;
                                                }
                                            }
                                            
                                            // Si aún no tenemos nombre de país pero tenemos código, buscar por código
                                            if (!$countryName && $countryCode) {
                                                $country = Country::where('iso2', $countryCode)->first();
                                                $countryName = $country ? $country->name : $countryCode;
                                            }
                                            
                                            // Si no hay información de país, mostrar "Desconocido"
                                            if (!$countryName) {
                                                return 'Desconocido';
                                            }
                                            
                                            // Determinar la moneda basada en el país
                                            $currencyMap = [
                                                // European countries
                                                'ES' => 'EUR', 'DE' => 'EUR', 'FR' => 'EUR', 'IT' => 'EUR', 
                                                'NL' => 'EUR', 'BE' => 'EUR', 'PT' => 'EUR', 'GR' => 'EUR',
                                                'AT' => 'EUR', 'IE' => 'EUR', 'FI' => 'EUR', 'SK' => 'EUR',
                                                // United Kingdom
                                                'GB' => 'GBP',
                                            ];
                                            
                                            // Usar la moneda del mapa si está disponible, o la predeterminada
                                            $currency = isset($currencyMap[$countryCode]) ? $currencyMap[$countryCode] : ($organization->currency ?? 'USD');
                                            
                                            return "{$country->emoji} {$country->name} ({$countryCode})  -  Moneda: {$currency}";
                                        })
                                        ->extraAttributes(['class' => 'font-medium'])
                                        ->visible(fn (Forms\Get $get) => (bool) $get('organization_id')),
                                ])
                                ->columnSpan(1),
                        ]),
                ]),
                
                // Segunda sección: Selección de Plan con precios claros
                Forms\Components\Section::make('Selección de Plan')
                ->schema([
                    Forms\Components\Split::make([
                        Forms\Components\Section::make([
                            Forms\Components\Radio::make('plan_id')
                                ->label('Selecciona el plan')
                                ->options(function (Forms\Get $get) {
                                    $organizationId = $get('organization_id');
                                    if (!$organizationId) return [];
                                    
                                    $organization = Organization::find($organizationId);
                                    if (!$organization) return [];
                                    
                                    // Obtener el código de país correctamente
                                    $countryCode = $organization->country_code;
                                    
                                    // Si country_code es NULL pero country_id está presente, obtener el código de país
                                    if (!$countryCode && $organization->country_id) {
                                        $country = Country::find($organization->country_id);
                                        if ($country) {
                                            $countryCode = $country->iso2;
                                        }
                                    }
                                    
                                    // Si aún no tenemos código de país, usar 'US' como fallback
                                    $countryCode = $countryCode ?: 'US';
                                    
                                    // SOLO obtener planes con precios para este país específico
                                    $planIds = DB::table('plan_price_by_countries')
                                        ->where('country_code', $countryCode)
                                        ->pluck('plan_id')
                                        ->toArray();
                                        
                                    // Eliminar duplicados de planIds
                                    $planIds = array_unique($planIds);
                                        
                                    // Obtener todos los planes activos que tienen precios para este país
                                    $plans = Plan::where('is_active', true)
                                        ->whereIn('id', $planIds)
                                        ->get();
                                    
                                    $options = [];
                                    
                                    foreach ($plans as $plan) {
                                        // Buscar el precio mensual (sin contrato)
                                        $monthlyPrice = DB::table('plan_price_by_countries')
                                            ->where('plan_id', $plan->id)
                                            ->where('country_code', $countryCode)
                                            ->where('billing_interval', 'monthly')
                                            ->first();
                                        
                                        if (!$monthlyPrice) continue; // Si no hay precio mensual, saltar este plan
                                        
                                        // Buscar el precio mensual con contrato anual
                                        $monthlyAnnualPrice = DB::table('plan_price_by_countries')
                                            ->where('plan_id', $plan->id)
                                            ->where('country_code', $countryCode)
                                            ->where('billing_interval', 'monthly_annual')
                                            ->first();
                                        
                                        // Buscar el precio de pago anual único
                                        $annualPrice = DB::table('plan_price_by_countries')
                                            ->where('plan_id', $plan->id)
                                            ->where('country_code', $countryCode)
                                            ->where('billing_interval', 'annual')
                                            ->first();
                                        
                                        // Determinar la moneda adecuada según el país
                                        $currencyMap = [
                                            // European countries
                                            'ES' => 'EUR', 'DE' => 'EUR', 'FR' => 'EUR', 'IT' => 'EUR', 
                                            'NL' => 'EUR', 'BE' => 'EUR', 'PT' => 'EUR', 'GR' => 'EUR',
                                            'AT' => 'EUR', 'IE' => 'EUR', 'FI' => 'EUR', 'SK' => 'EUR',
                                            // United Kingdom
                                            'GB' => 'GBP',
                                        ];
                                        
                                        $currency = isset($currencyMap[$countryCode]) ? $currencyMap[$countryCode] : ($monthlyPrice->currency ?? 'USD');
                                        $symbol = Currency::where('code', $currency)->value('symbol') ?? $currency;
                                        
                                        // Valores para la tabla
                                        $monthly = $monthlyPrice->price;
                                        $monthlyAnnual = $monthlyAnnualPrice ? $monthlyAnnualPrice->price : round($monthly * 0.85, 2);
                                        $annual = $annualPrice ? $annualPrice->price : round($monthly * 12 * 0.7, 2);
                                        
                                        // Calcular el equivalente mensual del plan anual
                                        $annualMonthly = round($annual / 12, 2);
                                        
                                        // Calcular el total anual para cada opción
                                        $monthlyTotal = $monthly * 12;
                                        $monthlyAnnualTotal = $monthlyAnnual * 12;
                                        
                                        // Construir la tabla HTML con mejor contraste para modo oscuro
                                        $table = '
                                        <div class="border rounded p-4 hover:bg-primary-500/10">
                                            <div class="font-bold text-xl mb-4 text-center text-primary-600 dark:text-primary-400">' . $plan->name . '</div>
                                            
                                            <table class="w-full border-collapse">
                                                <tr>
                                                    <th class="p-2 text-center border dark:border-gray-600"></th>
                                                    <th class="p-2 text-center border dark:border-gray-600 text-primary-600 dark:text-primary-400">Sin Contrato</th>
                                                    <th class="p-2 text-center border dark:border-gray-600 text-primary-600 dark:text-primary-400">Contrato Anual/Mensual</th>
                                                    <th class="p-2 text-center border dark:border-gray-600 text-primary-600 dark:text-primary-400">Anual Un Pago</th>
                                                </tr>
                                                <tr>
                                                    <td class="p-2 border dark:border-gray-600 font-medium">Descuento</td>
                                                    <td class="p-2 text-right border dark:border-gray-600">0%</td>
                                                    <td class="p-2 text-right border dark:border-gray-600 text-green-600 dark:text-green-400">15%</td>
                                                    <td class="p-2 text-right border dark:border-gray-600 text-green-600 dark:text-green-400">30%</td>
                                                </tr>
                                                <tr>
                                                    <td class="p-2 border dark:border-gray-600 font-medium">Precio Mensual</td>
                                                    <td class="p-2 text-right border dark:border-gray-600">' . $symbol . $monthly . '</td>
                                                    <td class="p-2 text-right border dark:border-gray-600">' . $symbol . $monthlyAnnual . '</td>
                                                    <td class="p-2 text-right border dark:border-gray-600">' . $symbol . $annualMonthly . '</td>
                                                </tr>
                                                <tr>
                                                    <td class="p-2 border dark:border-gray-600 font-medium">Total Anual</td>
                                                    <td class="p-2 text-right border dark:border-gray-600">' . $symbol . $monthlyTotal . ',00</td>
                                                    <td class="p-2 text-right border dark:border-gray-600">' . $symbol . $monthlyAnnualTotal . ',00</td>
                                                    <td class="p-2 text-right border dark:border-gray-600 font-bold text-primary-600 dark:text-primary-400">' . $symbol . $annual . '</td>
                                                </tr>
                                            </table>
                                            
                                            <div class="text-sm mt-3 dark:text-gray-300">' . nl2br(e($plan->description ?? '')) . '</div>
                                        </div>';
                                        
                                        $options[$plan->id] = new \Illuminate\Support\HtmlString($table);
                                    }
                                    
                                    return $options;
                                })
                                ->required()
                                ->live(),
                        ]),
                        
                        Forms\Components\Section::make([
                            Forms\Components\Placeholder::make('plan_features')
                                ->label('Características incluidas')
                                ->content(function (Forms\Get $get) {
                                    $planId = $get('plan_id');
                                    if (!$planId) return 'Selecciona un plan para ver sus características';
                                    
                                    $plan = Plan::find($planId);
                                    if (!$plan) return 'Plan no encontrado';
                                    
                                    // En este caso, obtén las características del plan usando DB para evitar problemas de relaciones
                                    $features = DB::table('plan_features')
                                        ->join('features', 'plan_features.feature_id', '=', 'features.id')
                                        ->where('plan_features.plan_id', $planId)
                                        ->select('features.name', 'features.code', 'plan_features.value')
                                        ->get();
                                    
                                    if ($features->isEmpty()) {
                                        return 'No hay características definidas para este plan';
                                    }
                                    
                                    $html = "<ul class='list-disc pl-5'>";
                                    foreach ($features as $feature) {
                                        $html .= "<li><strong>{$feature->name}</strong>";
                                        
                                        if ($feature->value) {
                                            $html .= ": {$feature->value}";
                                        }
                                        
                                        $html .= "</li>";
                                    }
                                    $html .= "</ul>";
                                    
                                    return new \Illuminate\Support\HtmlString($html);
                                }),
                        ])
                        ->grow(true),
                    ])->from('md'),
                ]),


                // Tercera sección: Ciclo de facturación
                Forms\Components\Section::make('Opciones de Facturación')
                    ->schema([
                        Forms\Components\Split::make([
                            Forms\Components\Section::make([
                                Forms\Components\Radio::make('billing_interval')
                                    ->label('Ciclo de Suscripción')
                                    ->options([
                                        'monthly' => 'Mensual - Sin compromiso (pay-as-you-go)',
                                        'annual-monthly' => 'Anual - Facturación mensual (15% descuento)',
                                        'annual-once' => 'Anual - Pago único (30% descuento)',
                                    ])
                                    ->descriptions([
                                        'monthly' => 'Pago mes a mes sin compromiso de permanencia',
                                        'annual-monthly' => 'Contrato anual con pagos mensuales. Ahorra un 15%.',
                                        'annual-once' => 'Contrato anual con un solo pago. Ahorra un 30%.',
                                    ])
                                    ->required()
                                    ->default('monthly')
                                    ->live(),
                            ]),
                            Forms\Components\Section::make([
                                Forms\Components\Select::make('discount_id')
                                    ->label('Código de descuento')
                                    ->relationship('discount', 'code', function ($query) {
                                        return $query->where('is_active', true)
                                                    ->where(function ($query) {
                                                        $query->whereNull('expires_at')
                                                            ->orWhere('expires_at', '>', now());
                                                    });
                                    })
                                    ->searchable()
                                    ->nullable()
                                    ->live()
                                    ->placeholder('Sin código de descuento'),
                                Forms\Components\Toggle::make('is_taxable')
                                    ->label('Requiere factura fiscal')
                                    ->helperText('Activa esta opción si el cliente necesita factura con datos fiscales')
                                    ->default(false),
                            ])->grow(true),
                        ])->from('md'),
                    ])
                    ->visible(fn (Forms\Get $get) => (bool) $get('plan_id')),


                // Sección de Complementos (Add-Ons)
                Forms\Components\Section::make('Complementos')
                    ->schema([
                        Forms\Components\Repeater::make('selected_addons')
                            ->label('Complementos disponibles')
                            ->schema([
                                Forms\Components\Hidden::make('addon_id'),
                                Forms\Components\Hidden::make('price'),
                                Forms\Components\Hidden::make('currency'),
                                Forms\Components\Hidden::make('code'),
                                Forms\Components\Hidden::make('is_removable'),
                                
                                Forms\Components\TextInput::make('name')
                                    ->label('Complemento')
                                    ->disabled(),
                                    
                                Forms\Components\TextInput::make('description')
                                    ->label('Descripción')
                                    ->disabled(),
                                    
                                Forms\Components\Grid::make(2)
                                    ->schema([
                                        Forms\Components\TextInput::make('quantity')
                                            ->label('Cantidad')
                                            ->numeric()
                                            ->minValue(function (Forms\Get $get) {
                                                // Si el complemento está afectado por un descuento que lo hace gratuito,
                                                // establecer valor mínimo según la cantidad gratuita permitida
                                                $organizationId = $get('../../organization_id');
                                                $discountId = $get('../../discount_id');
                                                $addonCode = $get('code');
                                                
                                                if ($discountId && $addonCode) {
                                                    $discount = Discount::find($discountId);
                                                    
                                                    if ($discount) {
                                                        $metadata = $discount->metadata ? json_decode($discount->metadata, true) : [];
                                                        
                                                        if (isset($metadata['addon_code']) && $metadata['addon_code'] === $addonCode) {
                                                            if (isset($metadata['is_free']) && $metadata['is_free']) {
                                                                // Si el descuento proporciona una cantidad gratis específica
                                                                if (isset($metadata['free_quantity'])) {
                                                                    return $metadata['free_quantity'];
                                                                }
                                                                // Si solo es gratis 1 unidad
                                                                return 1;
                                                            }
                                                        }
                                                    }
                                                }
                                                
                                                return 1; // Valor mínimo predeterminado
                                            })
                                            ->default(1)
                                            ->required()
                                            ->live()
                                            ->afterStateUpdated(function (Forms\Set $set, Forms\Get $get, $state) {
                                                // Calcular el subtotal basado en la cantidad y el precio
                                                $price = $get('price');
                                                $quantity = (int) $state;
                                                $discountId = $get('../../discount_id');
                                                $addonCode = $get('code');
                                                
                                                // Verificar si hay un descuento aplicado que afecte a este add-on
                                                $discountedPrice = $price;
                                                $freeQuantity = 0;
                                                
                                                if ($discountId && $addonCode) {
                                                    $discount = Discount::find($discountId);
                                                    
                                                    if ($discount) {
                                                        $metadata = $discount->metadata ? json_decode($discount->metadata, true) : [];
                                                        
                                                        if (isset($metadata['addon_code']) && $metadata['addon_code'] === $addonCode) {
                                                            // Si el descuento proporciona una cantidad gratis
                                                            if (isset($metadata['is_free']) && $metadata['is_free'] && isset($metadata['free_quantity'])) {
                                                                $freeQuantity = $metadata['free_quantity'];
                                                            }
                                                            // Si hay un porcentaje de descuento para este add-on
                                                            elseif (isset($metadata['discount_percentage'])) {
                                                                $discountPercent = $metadata['discount_percentage'];
                                                                $discountedPrice = $price * (100 - $discountPercent) / 100;
                                                            }
                                                        }
                                                    }
                                                }
                                                
                                                // Calcular subtotal considerando cantidades gratuitas
                                                $paidQuantity = max(0, $quantity - $freeQuantity);
                                                $subtotal = $paidQuantity * $discountedPrice;
                                                
                                                $set('subtotal', $subtotal);
                                            }),
                                            
                                        Forms\Components\TextInput::make('price_display')
                                            ->label('Precio unitario')
                                            ->disabled(),
                                    ]),
                                    
                                Forms\Components\TextInput::make('subtotal')
                                    ->label('Subtotal')
                                    ->disabled()
                                    ->formatStateUsing(function ($state, Forms\Get $get) {
                                        $currency = $get('currency') ?? 'USD';
                                        $symbol = Currency::where('code', $currency)->value('symbol') ?? $currency;
                                        
                                        // Verificar si hay cantidades gratuitas
                                        $discountId = $get('../../discount_id');
                                        $addonCode = $get('code');
                                        $quantity = (int) $get('quantity');
                                        $freeQuantity = 0;
                                        
                                        if ($discountId && $addonCode) {
                                            $discount = Discount::find($discountId);
                                            
                                            if ($discount) {
                                                $metadata = $discount->metadata ? json_decode($discount->metadata, true) : [];
                                                
                                                if (isset($metadata['addon_code']) && $metadata['addon_code'] === $addonCode && 
                                                    isset($metadata['is_free']) && $metadata['is_free'] && isset($metadata['free_quantity'])) {
                                                    $freeQuantity = $metadata['free_quantity'];
                                                    
                                                    if ($freeQuantity >= $quantity) {
                                                        return "GRATIS";
                                                    } else {
                                                        return "{$symbol}" . number_format($state, 2) . " ({$freeQuantity} gratis)";
                                                    }
                                                }
                                            }
                                        }
                                        
                                        return $state > 0 ? "{$symbol}" . number_format($state, 2) : "-";
                                    }),
                            ])
                            ->itemLabel(fn (array $state): ?string => 
                                $state['name'] ?? null)
                            ->collapsible(false)
                            ->columns(1)
                            ->columnSpanFull()
                            ->defaultItems(0)
                            ->addable(false)
                            ->reorderable(false)
                            ->deletable(false)
                            ->default(function (Forms\Get $get) {
                                $organizationId = $get('organization_id');
                                if (!$organizationId) return [];
                                
                                $organization = Organization::find($organizationId);
                                if (!$organization) return [];
                                
                                $countryCode = $organization->country_code;
                                
                                // Si country_code es NULL pero country_id está presente, obtener el código de país
                                if (!$countryCode && $organization->country_id) {
                                    $country = Country::find($organization->country_id);
                                    if ($country) {
                                        $countryCode = $country->iso2;
                                    }
                                }
                                
                                $countryCode = $countryCode ?: 'US';
                                
                                // Determinar la moneda adecuada según el país
                                $currencyMap = [
                                    // European countries
                                    'ES' => 'EUR', 'DE' => 'EUR', 'FR' => 'EUR', 'IT' => 'EUR', 
                                    'NL' => 'EUR', 'BE' => 'EUR', 'PT' => 'EUR', 'GR' => 'EUR',
                                    'AT' => 'EUR', 'IE' => 'EUR', 'FI' => 'EUR', 'SK' => 'EUR',
                                    // United Kingdom
                                    'GB' => 'GBP',
                                ];
                                
                                // Determinar la moneda a usar basada en el país de la organización
                                $displayCurrency = isset($currencyMap[$countryCode]) ? $currencyMap[$countryCode] : 'USD';
                                $symbol = Currency::where('code', $displayCurrency)->value('symbol') ?? $displayCurrency;
                                
                                // Obtener todos los addons activos
                                $addons = AddOn::where('is_active', true)->get();
                                
                                $items = [];
                                
                                foreach ($addons as $addon) {
                                    $price = $addon->price;
                                    $code = $addon->code;
                                    $description = $addon->description;
                                    
                                    // Determinar si este addon es removible basado en metadata
                                    $metadata = $addon->metadata ? json_decode($addon->metadata, true) : [];
                                    $isRemovable = !(isset($metadata['non_removable']) && $metadata['non_removable']);
                                    
                                    // Determinar si este add-on soporta múltiples cantidades
                                    $supportsQuantity = isset($metadata['supports_quantity']) && $metadata['supports_quantity'];
                                    
                                    // Obtener unidad de medida si existe
                                    $unit = isset($metadata['unit']) ? $metadata['unit'] : '';
                                    
                                    // Formatear el nombre con la unidad si corresponde
                                    $displayName = $addon->name;
                                    if ($unit) {
                                        $displayName .= " ({$unit})";
                                    }
                                    
                                    // Determinar si es cuantificable
                                    $minValue = isset($metadata['min_quantity']) ? $metadata['min_quantity'] : 1;
                                    $maxValue = isset($metadata['max_quantity']) ? $metadata['max_quantity'] : null;
                                    
                                    // Añadir advertencia sobre removibilidad a la descripción si es necesario
                                    $fullDescription = $description;
                                    if (!$isRemovable) {
                                        $fullDescription .= ' [IMPORTANTE: Este complemento no puede ser removido después de añadirlo]';
                                    }
                                    
                                    // También añadir información sobre las cantidades si corresponde
                                    if ($supportsQuantity && isset($metadata['quantity_description'])) {
                                        $fullDescription .= ' ' . $metadata['quantity_description'];
                                    }
                                    
                                    $items[] = [
                                        'addon_id' => $addon->id,
                                        'name' => $displayName,
                                        'description' => $fullDescription,
                                        'code' => $code,
                                        'price' => $price,
                                        'currency' => $displayCurrency,
                                        'price_display' => "{$symbol}{$price}",
                                        'quantity' => $minValue,
                                        'subtotal' => $price * $minValue,
                                        'is_removable' => $isRemovable,
                                        'supports_quantity' => $supportsQuantity,
                                        'min_quantity' => $minValue,
                                        'max_quantity' => $maxValue,
                                    ];
                                }
                                
                                return $items;
                            })
                            ->live(),
                        
                        // Campo adicional para gestionar las selecciones
                        Forms\Components\CheckboxList::make('addon_selections')
                            ->label('Añadir complementos a tu suscripción')
                            ->options(function (Forms\Get $get) {
                                $organizationId = $get('organization_id');
                                if (!$organizationId) return [];
                                
                                $addons = AddOn::where('is_active', true)->get();
                                $options = [];
                                
                                foreach ($addons as $addon) {
                                    $metadata = $addon->metadata ? json_decode($addon->metadata, true) : [];
                                    $unit = isset($metadata['unit']) ? " ({$metadata['unit']})" : '';
                                    
                                    $options[$addon->id] = $addon->name . $unit;
                                }
                                
                                return $options;
                            })
                            ->descriptions(function (Forms\Get $get) {
                                $organizationId = $get('organization_id');
                                if (!$organizationId) return [];
                                
                                $organization = Organization::find($organizationId);
                                if (!$organization) return [];
                                
                                $countryCode = $organization->country_code;
                                
                                // Determinar moneda
                                $currencyMap = [
                                    'ES' => 'EUR', 'DE' => 'EUR', 'FR' => 'EUR', 'IT' => 'EUR', 
                                    'NL' => 'EUR', 'BE' => 'EUR', 'PT' => 'EUR', 'GR' => 'EUR',
                                    'AT' => 'EUR', 'IE' => 'EUR', 'FI' => 'EUR', 'SK' => 'EUR',
                                    'GB' => 'GBP',
                                ];
                                
                                $currency = isset($currencyMap[$countryCode]) ? $currencyMap[$countryCode] : 'USD';
                                $symbol = Currency::where('code', $currency)->value('symbol') ?? $currency;
                                
                                $discountId = $get('discount_id');
                                $discount = $discountId ? Discount::find($discountId) : null;
                                
                                // Obtener todos los addons activos
                                $addons = AddOn::where('is_active', true)->get();
                                $descriptions = [];
                                
                                foreach ($addons as $addon) {
                                    $price = $addon->price;
                                    $description = $addon->description;
                                    
                                    // Determinar si este addon es removible basado en metadata
                                    $metadata = $addon->metadata ? json_decode($addon->metadata, true) : [];
                                    $isRemovable = !(isset($metadata['non_removable']) && $metadata['non_removable']);
                                    
                                    // Verificar si este add-on está afectado por un descuento
                                    $priceDisplay = "{$symbol}{$price}";
                                    $discountText = "";
                                    
                                    if ($discount) {
                                        $discountMeta = $discount->metadata ? json_decode($discount->metadata, true) : [];
                                        
                                        if (isset($discountMeta['addon_code']) && $discountMeta['addon_code'] === $addon->code) {
                                            // Si el descuento proporciona unidades gratuitas
                                            if (isset($discountMeta['is_free']) && $discountMeta['is_free']) {
                                                if (isset($discountMeta['free_quantity']) && $discountMeta['free_quantity'] > 0) {
                                                    $freeQty = $discountMeta['free_quantity'];
                                                    $discountText = " <span class='text-green-600 dark:text-green-400 font-medium'>({$freeQty} unidad(es) gratis con tu código de descuento)</span>";
                                                } else {
                                                    $discountText = " <span class='text-green-600 dark:text-green-400 font-medium'>(Gratis con tu código de descuento)</span>";
                                                }
                                            } elseif (isset($discountMeta['discount_percentage'])) {
                                                $discountPercent = $discountMeta['discount_percentage'];
                                                $discountedPrice = $price * (100 - $discountPercent) / 100;
                                                $priceDisplay = "<span class='line-through text-gray-400'>{$symbol}{$price}</span> <span class='text-green-600 dark:text-green-400 font-medium'>{$symbol}{$discountedPrice}</span>";
                                                $discountText = " <span class='text-green-600 dark:text-green-400 font-medium'>({$discountPercent}% descuento)</span>";
                                            }
                                        }
                                    }
                                    
                                    // Añadir advertencia sobre removibilidad
                                    $removableText = $isRemovable ? 
                                        "" : 
                                        " <span class='text-orange-500 dark:text-orange-400 font-medium'>(No puede ser removido después)</span>";
                                    
                                    $descriptions[$addon->id] = new \Illuminate\Support\HtmlString("{$description} - {$priceDisplay}{$discountText}{$removableText}");
                                }
                                
                                return $descriptions;
                            })
                            ->bulkToggleable()
                            ->columns(2)
                            ->allowHtml()
                            ->live()
                            ->afterStateUpdated(function (Forms\Set $set, Forms\Get $get, $state) {
                                $addonItems = $get('selected_addons') ?: [];
                                
                                // Filtrar y actualizar el arreglo de addons
                                $updatedItems = [];
                                
                                // Primero añadir los ítems seleccionados
                                if (is_array($state)) {
                                    foreach ($addonItems as $index => $item) {
                                        $addonId = $item['addon_id'] ?? null;
                                        
                                        // Si este add-on está en la selección, añadirlo a los updated items
                                        if ($addonId && in_array($addonId, $state)) {
                                            $updatedItems[] = $item;
                                        }
                                    }
                                    
                                    // Añadir nuevos ítems que no estaban antes
                                    foreach ($state as $addonId) {
                                        $exists = false;
                                        foreach ($updatedItems as $item) {
                                            if (($item['addon_id'] ?? null) == $addonId) {
                                                $exists = true;
                                                break;
                                            }
                                        }
                                        
                                        if (!$exists) {
                                            // Buscar el addon y añadirlo
                                            $addon = AddOn::find($addonId);
                                            if ($addon) {
                                                // Determinar moneda y otros datos
                                                $organizationId = $get('organization_id');
                                                $organization = Organization::find($organizationId);
                                                $countryCode = $organization ? ($organization->country_code ?? 'US') : 'US';
                                                
                                                $currencyMap = [
                                                    'ES' => 'EUR', 'DE' => 'EUR', 'FR' => 'EUR', 'IT' => 'EUR', 
                                                    'NL' => 'EUR', 'BE' => 'EUR', 'PT' => 'EUR', 'GR' => 'EUR',
                                                    'AT' => 'EUR', 'IE' => 'EUR', 'FI' => 'EUR', 'SK' => 'EUR',
                                                    'GB' => 'GBP',
                                                ];
                                                
                                                $displayCurrency = isset($currencyMap[$countryCode]) ? $currencyMap[$countryCode] : 'USD';
                                                $symbol = Currency::where('code', $displayCurrency)->value('symbol') ?? $displayCurrency;
                                                
                                                $metadata = $addon->metadata ? json_decode($addon->metadata, true) : [];
                                                $isRemovable = !(isset($metadata['non_removable']) && $metadata['non_removable']);
                                                $unit = isset($metadata['unit']) ? " ({$metadata['unit']})" : '';
                                                $minValue = isset($metadata['min_quantity']) ? $metadata['min_quantity'] : 1;
                                                
                                                $updatedItems[] = [
                                                    'addon_id' => $addon->id,
                                                    'name' => $addon->name . $unit,
                                                    'description' => $addon->description,
                                                    'code' => $addon->code,
                                                    'price' => $addon->price,
                                                    'currency' => $displayCurrency,
                                                    'price_display' => "{$symbol}{$addon->price}",
                                                    'quantity' => $minValue,
                                                    'subtotal' => $addon->price * $minValue,
                                                    'is_removable' => $isRemovable,
                                                ];
                                            }
                                        }
                                    }
                                }
                                
                                $set('selected_addons', $updatedItems);
                            })
                            ->helperText('Selecciona los complementos que deseas añadir. Los precios se ajustan según el ciclo de facturación.'),
                            
                        Forms\Components\TextInput::make('stripe_payment_link')
                            ->required()
                            ->suffixAction(GenerateStripeLinkAction::make('stripe_payment_link')),
                        
                            // Campos ocultos para procesar los add-ons seleccionados
                        Forms\Components\Hidden::make('selected_addons_data')
                            ->dehydrateStateUsing(function (Forms\Get $get) {
                                $addonItems = $get('selected_addons') ?? [];
                                $addonData = [];
                                
                                foreach ($addonItems as $item) {
                                    $addonId = $item['addon_id'] ?? null;
                                    if (!$addonId) continue;
                                    
                                    $addonData[] = [
                                        'addon_id' => $addonId,
                                        'quantity' => $item['quantity'] ?? 1,
                                        'price' => $item['price'] ?? 0,
                                        'currency' => $item['currency'] ?? 'USD',
                                        'is_removable' => $item['is_removable'] ?? true,
                                    ];
                                }
                                
                                return json_encode($addonData);
                            }),
                    ])
                    ->visible(fn (Forms\Get $get) => (bool) $get('plan_id')),
            
            
            // Sección de Resumen de Facturación
            Forms\Components\Section::make('Resumen de Facturación')
            ->schema([
                Forms\Components\Section::make()
                    ->schema([
                        Forms\Components\Placeholder::make('summary')
                            ->content(function (Forms\Get $get) {
                                $organizationId = $get('organization_id');
                                $planId = $get('plan_id');
                                $billingInterval = $get('billing_interval') ?? 'monthly';
                                $discountId = $get('discount_id');
                                $addonItems = $get('selected_addons') ?? [];
                                
                                if (!$organizationId || !$planId) {
                                    return 'Selecciona una organización y un plan para ver el resumen.';
                                }
                                
                                $organization = Organization::find($organizationId);
                                if (!$organization) {
                                    return 'Organización no encontrada';
                                }
                                
                                // Obtener el código de país correctamente
                                $countryCode = $organization->country_code;
                                
                                // Si country_code es NULL pero country_id está presente, obtener el código de país
                                if (!$countryCode && $organization->country_id) {
                                    $country = Country::find($organization->country_id);
                                    if ($country) {
                                        $countryCode = $country->iso2;
                                    }
                                }
                                
                                // Si aún no tenemos código de país, usar 'US' como fallback
                                $countryCode = $countryCode ?: 'US';
                                
                                // Determinar la moneda basada en el país
                                $currencyMap = [
                                    // European countries
                                    'ES' => 'EUR', 'DE' => 'EUR', 'FR' => 'EUR', 'IT' => 'EUR', 
                                    'NL' => 'EUR', 'BE' => 'EUR', 'PT' => 'EUR', 'GR' => 'EUR',
                                    'AT' => 'EUR', 'IE' => 'EUR', 'FI' => 'EUR', 'SK' => 'EUR',
                                    // United Kingdom
                                    'GB' => 'GBP',
                                ];
                                
                                // Obtener el precio para este plan y país
                                $price = DB::table('plan_price_by_countries')
                                    ->where('plan_id', $planId)
                                    ->where('country_code', $countryCode)
                                    ->where('billing_interval', 'monthly')
                                    ->first();
                                
                                // Si no hay precio específico para este país, usar cualquier precio disponible
                                if (!$price) {
                                    $price = DB::table('plan_price_by_countries')
                                        ->where('plan_id', $planId)
                                        ->where('billing_interval', 'monthly')
                                        ->first();
                                }
                                
                                if (!$price) {
                                    return 'No hay precio definido para este plan';
                                }
                                
                                $basePrice = $price->price;
                                
                                // IMPORTANTE: Usar la moneda correcta según el país
                                $currency = isset($currencyMap[$countryCode]) ? $currencyMap[$countryCode] : ($price->currency ?? 'USD');
                                $symbol = Currency::where('code', $currency)->value('symbol') ?? $currency;
                                
                                // Obtener información del descuento (si existe)
                                $discount = null;
                                if ($discountId) {
                                    $discount = Discount::find($discountId);
                                }
                                
                                // Construir HTML del resumen
                                $html = "<div class='space-y-3'>";
                                
                                // Obtener nombre del plan
                                $plan = Plan::find($planId);
                                $planName = $plan ? $plan->name : "Plan #{$planId}";
                                
                                // Encabezado del resumen
                                $html .= "<div class='mb-4 py-2 border-b dark:border-gray-700'>
                                    <div class='font-bold text-lg'>Resumen de tu suscripción</div>
                                    <div class='text-sm text-gray-500 dark:text-gray-400'>
                                        Organización: {$organization->name} ({$countryCode})
                                    </div>
                                </div>";
                                
                                // Subtítulo del Plan
                                $html .= "<div class='font-medium mb-2'>Plan seleccionado:</div>";
                                
                                // Precio base del plan
                                $html .= "<div class='flex justify-between items-center mb-1'>";
                                $html .= "<span class='font-medium'>{$planName}</span>";
                                $html .= "<span>{$symbol}{$basePrice}/mes</span>";
                                $html .= "</div>";
                                
                                // Tipo de facturación y descuentos asociados
                                $total = $basePrice;
                                $period = 'mes';
                                
                                if ($billingInterval === 'annual-monthly') {
                                    // Buscar precio específico para monthly_annual
                                    $monthlyAnnualPrice = DB::table('plan_price_by_countries')
                                        ->where('plan_id', $planId)
                                        ->where('country_code', $countryCode)
                                        ->where('billing_interval', 'monthly_annual')
                                        ->first();
                                        
                                    if ($monthlyAnnualPrice) {
                                        $discountPercentage = $monthlyAnnualPrice->discount_percentage;
                                        $discountAmount = $basePrice - $monthlyAnnualPrice->price;
                                        $discountedPrice = $monthlyAnnualPrice->price;
                                    } else {
                                        $discountPercentage = 15;
                                        $discountAmount = $basePrice * 0.15;
                                        $discountedPrice = $basePrice - $discountAmount;
                                    }
                                    
                                    $html .= "<div class='flex justify-between items-center text-green-600 dark:text-green-400 text-sm'>";
                                    $html .= "<span>Descuento por contrato anual ({$discountPercentage}%)</span>";
                                    $html .= "<span>-{$symbol}" . number_format($discountAmount, 2) . "/mes</span>";
                                    $html .= "</div>";
                                    
                                    $html .= "<div class='flex justify-between items-center font-medium text-sm pt-1 pb-2 border-b dark:border-gray-700'>";
                                    $html .= "<span>Subtotal del plan</span>";
                                    $html .= "<span>{$symbol}" . number_format($discountedPrice, 2) . "/mes</span>";
                                    $html .= "</div>";
                                    
                                    $total = $discountedPrice;
                                } elseif ($billingInterval === 'annual-once') {
                                    // Buscar precio específico para annual
                                    $annualPrice = DB::table('plan_price_by_countries')
                                        ->where('plan_id', $planId)
                                        ->where('country_code', $countryCode)
                                        ->where('billing_interval', 'annual')
                                        ->first();
                                        
                                    if ($annualPrice) {
                                        $annualBaseCost = $annualPrice->original_price ?? ($basePrice * 12);
                                        $discountPercentage = $annualPrice->discount_percentage;
                                        $discountAmount = $annualBaseCost - $annualPrice->price;
                                        $discountedPrice = $annualPrice->price;
                                    } else {
                                        $annualBaseCost = $basePrice * 12;
                                        $discountPercentage = 30;
                                        $discountAmount = $annualBaseCost * 0.3;
                                        $discountedPrice = $annualBaseCost - $discountAmount;
                                    }
                                    
                                    $html .= "<div class='flex justify-between items-center text-sm'>";
                                    $html .= "<span>Subtotal anual ({$basePrice} × 12 meses)</span>";
                                    $html .= "<span>{$symbol}" . number_format($annualBaseCost, 2) . "/año</span>";
                                    $html .= "</div>";
                                    
                                    $html .= "<div class='flex justify-between items-center text-green-600 dark:text-green-400 text-sm'>";
                                    $html .= "<span>Descuento por contrato anual ({$discountPercentage}%)</span>";
                                    $html .= "<span>-{$symbol}" . number_format($discountAmount, 2) . "</span>";
                                    $html .= "</div>";
                                    
                                    $html .= "<div class='flex justify-between items-center font-medium text-sm pt-1 pb-2 border-b dark:border-gray-700'>";
                                    $html .= "<span>Subtotal del plan</span>";
                                    $html .= "<span>{$symbol}" . number_format($discountedPrice, 2) . "/año</span>";
                                    $html .= "</div>";
                                    
                                    $total = $discountedPrice;
                                    $period = 'año';
                                } else {
                                    // Plan mensual estándar (sin descuentos)
                                    $html .= "<div class='flex justify-between items-center font-medium text-sm pt-1 pb-2 border-b dark:border-gray-700'>";
                                    $html .= "<span>Subtotal del plan</span>";
                                    $html .= "<span>{$symbol}" . number_format($basePrice, 2) . "/mes</span>";
                                    $html .= "</div>";
                                }
                                
                                // Agregar complementos seleccionados
                                $addonsTotal = 0;
                                
                                if (!empty($addonItems) && is_array($addonItems)) {
                                    $html .= "<div class='font-medium mt-4 mb-2'>Complementos seleccionados:</div>";
                                    
                                    foreach ($addonItems as $item) {
                                        // Asegurarnos de que tenemos todos los datos necesarios
                                        if (!isset($item['addon_id']) || !isset($item['price']) || !isset($item['quantity'])) {
                                            continue;
                                        }
                                        
                                        $addonId = $item['addon_id'];
                                        $addonPrice = floatval($item['price']);
                                        $quantity = intval($item['quantity']);
                                        $addonName = $item['name'] ?? "Complemento #{$addonId}";
                                        
                                        // Precio original y aplicación de descuentos específicos por addon
                                        $originalPrice = $addonPrice;
                                        $freeQuantity = 0;
                                        $isDiscounted = false;
                                        
                                        // Verificar si este add-on está afectado por el descuento
                                        if ($discount && isset($item['code'])) {
                                            $addonCode = $item['code'];
                                            $discountMeta = $discount->metadata ? json_decode($discount->metadata, true) : [];
                                            
                                            if (isset($discountMeta['addon_code']) && $discountMeta['addon_code'] === $addonCode) {
                                                $isDiscounted = true;
                                                
                                                // Si el descuento proporciona unidades gratuitas
                                                if (isset($discountMeta['is_free']) && $discountMeta['is_free']) {
                                                    if (isset($discountMeta['free_quantity']) && $discountMeta['free_quantity'] > 0) {
                                                        $freeQuantity = $discountMeta['free_quantity'];
                                                    } else {
                                                        $freeQuantity = 1; // Por defecto, 1 unidad gratis
                                                    }
                                                } elseif (isset($discountMeta['discount_percentage'])) {
                                                    $discountPercent = $discountMeta['discount_percentage'];
                                                    $addonPrice = $addonPrice * (100 - $discountPercent) / 100;
                                                }
                                            }
                                        }
                                        
                                        // Calcular el precio total con cantidades y descuentos
                                        $paidQuantity = max(0, $quantity - $freeQuantity);
                                        $addonSubtotal = $paidQuantity * $addonPrice;
                                        
                                        // Ajustar precio según ciclo de facturación
                                        if ($billingInterval === 'annual-once') {
                                            $addonSubtotal = $addonSubtotal * 12 * 0.7; // 30% descuento anual
                                        } elseif ($billingInterval === 'annual-monthly') {
                                            $addonSubtotal = $addonSubtotal * 0.85; // 15% descuento
                                        }
                                        
                                        // Mostrar la información del add-on
                                        $html .= "<div class='flex justify-between items-center text-sm'>";
                                        $html .= "<span>{$addonName} × {$quantity}";
                                        
                                        // Mostrar información de unidades gratuitas si aplica
                                        if ($freeQuantity > 0) {
                                            $html .= " <span class='text-green-600 dark:text-green-400'>({$freeQuantity} gratis)</span>";
                                        }
                                        
                                        $html .= "</span>";
                                        
                                        // Mostrar precio con formato adecuado
                                        if ($freeQuantity >= $quantity) {
                                            $html .= "<span class='text-green-600 dark:text-green-400 font-medium'>GRATIS</span>";
                                        } else {
                                            if ($isDiscounted && $freeQuantity == 0) {
                                                // Si hay descuento pero no unidades gratuitas
                                                $originalSubtotal = $quantity * $originalPrice;
                                                if ($billingInterval === 'annual-once') {
                                                    $originalSubtotal = $originalSubtotal * 12 * 0.7;
                                                } elseif ($billingInterval === 'annual-monthly') {
                                                    $originalSubtotal = $originalSubtotal * 0.85;
                                                }
                                                
                                                $html .= "<span>
                                                    <span class='line-through text-gray-400'>{$symbol}" . number_format($originalSubtotal, 2) . "</span> 
                                                    <span class='text-green-600 dark:text-green-400'>{$symbol}" . number_format($addonSubtotal, 2) . "</span>
                                                </span>";
                                            } else {
                                                if ($billingInterval === 'annual-once') {
                                                    $html .= "<span>{$symbol}" . number_format($addonSubtotal, 2) . "/año</span>";
                                                } else {
                                                    $html .= "<span>{$symbol}" . number_format($addonSubtotal, 2) . "/mes</span>";
                                                }
                                            }
                                        }
                                        
                                        $html .= "</div>";
                                        
                                        // Verificar si es removible
                                        $isRemovable = $item['is_removable'] ?? true;
                                        if (!$isRemovable) {
                                            $html .= "<div class='text-orange-500 dark:text-orange-400 text-xs ml-4 mb-1'>
                                                <span class='font-medium'>Nota:</span> Este complemento no podrá ser removido posteriormente
                                            </div>";
                                        }
                                        
                                        $addonsTotal += $addonSubtotal;
                                    }
                                    
                                    $html .= "<div class='flex justify-between items-center font-medium text-sm pt-2 pb-2 border-t border-b dark:border-gray-700'>";
                                    $html .= "<span>Subtotal complementos</span>";
                                    
                                    if ($billingInterval === 'annual-once') {
                                        $html .= "<span>{$symbol}" . number_format($addonsTotal, 2) . "/año</span>";
                                    } else {
                                        $html .= "<span>{$symbol}" . number_format($addonsTotal, 2) . "/mes</span>";
                                    }
                                    
                                    $html .= "</div>";
                                }
                                
                                if ($addonsTotal > 0) {
                                    $total += $addonsTotal;
                                }
                                
                                // Aplicar descuento global si existe (que no sea específico de add-ons)
                                $globalDiscountApplied = false;
                                if ($discount) {
                                    $discountType = $discount->type ?? 'fixed';
                                    $discountValue = $discount->value ?? 0;
                                    $discountMeta = $discount->metadata ? json_decode($discount->metadata, true) : [];
                                    
                                    // Solo aplicar descuento global si no es específico para un add-on
                                    $isAddonSpecific = isset($discountMeta['addon_code']);
                                    
                                    if (!$isAddonSpecific) {
                                        $globalDiscountApplied = true;
                                        $discountAmount = 0;
                                        
                                        if ($discountType === 'percentage') {
                                            $discountAmount = $total * ($discountValue / 100);
                                            $discountLabel = "{$discountValue}%";
                                        } else {
                                            $discountAmount = $discountValue;
                                            $discountLabel = "{$symbol}{$discountValue}";
                                        }
                                        
                                        $html .= "<div class='flex justify-between items-center text-green-600 dark:text-green-400 text-sm mt-4'>";
                                        $html .= "<span>Código de descuento: {$discount->code} ({$discountLabel})</span>";
                                        $html .= "<span>-{$symbol}" . number_format($discountAmount, 2) . "</span>";
                                        $html .= "</div>";
                                        
                                        $total -= $discountAmount;
                                    }
                                }
                                
                                // Total final
                                $html .= "<div class='flex justify-between items-center font-bold text-lg mt-4 pt-3 border-t dark:border-gray-700'>";
                                $html .= "<span>Total</span>";
                                $html .= "<span>{$symbol}" . number_format($total, 2) . "/{$period}</span>";
                                $html .= "</div>";
                                
                                // Notas adicionales
                                if ($period === 'año') {
                                    $monthlyEquivalent = $total / 12;
                                    $html .= "<div class='text-sm mt-2'>
                                        Equivalente mensual: <span class='font-medium'>{$symbol}" . number_format($monthlyEquivalent, 2) . "/mes</span>
                                    </div>";
                                } elseif ($period === 'mes') {
                                    $annualTotal = $total * 12;
                                    $html .= "<div class='text-sm mt-2'>
                                        Proyección anual: <span class='font-medium'>{$symbol}" . number_format($annualTotal, 2) . "/año</span>
                                    </div>";
                                }
                                
                                $html .= "</div>";
                                
                                return new \Illuminate\Support\HtmlString($html);
                            }),
                    ])
                    ->columnSpanFull(),
            ])
            ->visible(fn (Forms\Get $get) => (bool) $get('plan_id')),
                
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('organization.name')
                    ->label('Organización')
                    ->sortable()
                    ->searchable(),
                    
                Tables\Columns\TextColumn::make('plan.name')
                    ->label('Plan')
                    ->sortable(),
                    
                Tables\Columns\TextColumn::make('billing_interval')
                    ->label('Facturación')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'monthly' => 'Mensual',
                        'annual-monthly' => 'Anual (mensual)',
                        'annual-once' => 'Anual (único)',
                        default => $state,
                    })
                    ->colors([
                        'primary' => 'monthly',
                        'success' => 'annual-monthly',
                        'warning' => 'annual-once',
                    ]),
                    
                Tables\Columns\TextColumn::make('stripe_status')
                    ->label('Estado')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => match ($state ?? '') {
                        'active' => 'Activa',
                        'trialing' => 'En prueba',
                        'incomplete' => 'Incompleta',
                        'past_due' => 'Pago vencido',
                        'canceled' => 'Cancelada',
                        'unpaid' => 'No pagada',
                        '' => 'Pendiente',
                        default => $state ?? 'Pendiente',
                    })
                    ->colors([
                        'success' => 'active',
                        'info' => 'trialing',
                        'warning' => ['incomplete', 'past_due'],
                        'danger' => ['canceled', 'unpaid'],
                    ]),
                    
                Tables\Columns\TextColumn::make('starts_at')
                    ->label('Inicio')
                    ->date('d/m/Y')
                    ->sortable(),
                    
                Tables\Columns\TextColumn::make('ends_at')
                    ->label('Fin')
                    ->date('d/m/Y')
                    ->sortable()
                    ->placeholder('Continua'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('plan_id')
                    ->label('Plan')
                    ->relationship('plan', 'name'),
                    
                Tables\Filters\SelectFilter::make('stripe_status')
                    ->label('Estado')
                    ->options([
                        'active' => 'Activa',
                        'trialing' => 'En prueba',
                        'incomplete' => 'Incompleta',
                        'past_due' => 'Pago vencido',
                        'canceled' => 'Cancelada',
                        'unpaid' => 'No pagada',
                    ]),
                    
                Tables\Filters\Filter::make('ends_at')
                    ->label('Suscripciones activas')
                    ->toggle()
                    ->query(fn (Builder $query): Builder => $query->whereNull('ends_at')),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\Action::make('checkout')
                    ->label('Checkout a Stripe')
                    ->icon('heroicon-o-credit-card')
                    ->color('success')
                    ->requiresConfirmation()
                    ->action(function (Subscription $record) {
                        try {
                            // Validaciones previas
/*                             if ($record->status !== 'incomplete') {
                                throw new \Exception('Solo se pueden enviar a Stripe suscripciones en estado pendiente');
                            } */
                
                            // Obtener URL de checkout
                            $url = app(StripeCheckoutService::class)
                                ->createCheckoutSession($record);
                
                            // Redirigir al usuario
                            return redirect()->away($url);
                
                        } catch (\Exception $e) {
                            // Notificación de error amigable
                            Notification::make()
                                ->title('Error al generar enlace de pago')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                
                            // Log del error para seguimiento
                            Log::error('Checkout Stripe Error: ' . $e->getMessage(), [
                                'subscription_id' => $record->id,
                                'error' => $e
                            ]);
                
                            // Prevenir redireccionamiento
                            return null;
                        }
                    }),
                    // Visibilidad condicional
                    /* ->visible(fn (Subscription $record) => 
                        $record->status === 'incomplete' && 
                        $record->plan && 
                        $record->organization
                ) */
                Tables\Actions\Action::make('cancelSubscription')
                    ->label('Cancelar')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->action(function (Subscription $record) {
                        $record->ends_at = now();
                        $record->save();
                    })
                    ->visible(fn (Subscription $record) => $record && !$record->ends_at),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }
    
    public static function getRelations(): array
    {
        return [
/*             RelationManagers\AddOnsRelationManager::class,
            RelationManagers\MonitoredCountriesRelationManager::class,
            RelationManagers\InvoicesRelationManager::class, */
        ];
    }
    
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSubscriptions::route('/'),
            'create' => Pages\CreateSubscription::route('/create'),
            'edit' => Pages\EditSubscription::route('/{record}/edit'),
        ];
    }
    
    /**
     * Métodos para procesar la creación/actualización de suscripciones
     */
    public static function mutateFormDataBeforeCreate(array $data): array
    {
        // Procesar los addons seleccionados
        if (isset($data['selected_addons'])) {
            $selectedAddons = json_decode($data['selected_addons'], true);
            unset($data['selected_addons']);
            unset($data['addon_items']);
            
            // Este campo lo guardaremos para usarlo después de crear la suscripción
            $data['_addons'] = $selectedAddons;
        }
        
        // Añadir valores por defecto para campos requeridos
        $data['stripe_id'] = 'sub_' . uniqid(); // Generar un ID único para la suscripción
        $data['ends_at'] = null; // Inicialmente no tiene fecha de finalización
        $data['type'] = 'regular'; 
        $data['starts_at'] = now();
        $data['stripe_status'] = 'pending';
        $data['status'] = 'pending';
        
        return $data;
    }
    
    public static function afterCreate(Subscription $record, array $data): void
    {
        
        $stripeService = new StripeCheckoutService();
        $checkoutUrl = $stripeService->createCheckoutSession($record);
        // Procesar los addons después de crear la suscripción
        if (isset($data['_addons'])) {
            foreach ($data['_addons'] as $addon) {
                DB::table('subscription_add_ons')->insert([
                    'subscription_id' => $record->id,
                    'add_on_id' => $addon['addon_id'],
                    'quantity' => $addon['quantity'],
                    'price' => $addon['price'],
                    'currency' => $addon['currency'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    
        // Crear suscripción en Stripe
        try {
            $organization = $record->organization;
            $plan = $record->plan;
    
            // Asegúrate de que la organización tenga un cliente de Stripe
            if (!$organization->stripe_id) {
                $organization->createAsStripeCustomer([
                    'name' => $organization->name,
                    'email' => $organization->email, // Asume que tienes un campo de email
                ]);
            }
    
            // Crear suscripción de Stripe
            $stripeSubscription = $organization->newSubscription(
                'default', 
                $plan->stripe_price_id
            )
            ->create(null, [
                'metadata' => [
                    'subscription_id' => $record->id,
                    'organization_id' => $organization->id,
                    'plan_id' => $plan->id,
                ]
            ]);
    
            // Actualizar el registro de suscripción con los detalles de Stripe
            $record->update([
                'stripe_id' => $stripeSubscription->id,
                'stripe_status' => $stripeSubscription->status,
            ]);
    
        } catch (\Exception $e) {
            // Manejar errores de Stripe
            Log::error('Error al crear suscripción en Stripe: ' . $e->getMessage());
            
            // Opcional: lanzar una excepción o manejar el error según tus necesidades
            throw new \Exception('No se pudo crear la suscripción en Stripe: ' . $e->getMessage());
        }
    }
    
    public static function mutateFormDataBeforeUpdate(array $data, Subscription $record): array
    {
        // Similar a mutateFormDataBeforeCreate pero para actualizaciones
        if (isset($data['selected_addons'])) {
            $selectedAddons = json_decode($data['selected_addons'], true);
            unset($data['selected_addons']);
            unset($data['addon_items']);
            
            $data['_addons'] = $selectedAddons;
        }
        
        return $data;
    }
    
    public static function afterUpdate(Subscription $record, array $data): void
    {
        // Actualizar addons
        if (isset($data['_addons'])) {
            // Primero eliminar todos los addons actuales
            DB::table('subscription_add_ons')
                ->where('subscription_id', $record->id)
                ->delete();
            
            // Luego insertar los nuevos
            foreach ($data['_addons'] as $addon) {
                DB::table('subscription_add_ons')->insert([
                    'subscription_id' => $record->id,
                    'add_on_id' => $addon['addon_id'],
                    'quantity' => $addon['quantity'],
                    'price' => $addon['price'],
                    'currency' => $addon['currency'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
            
            // Actualizar en Stripe si es necesario
            /*
            if ($record->stripe_id) {
                try {
                    // Implementación para actualizar en Stripe
                    // Similar a la creación pero usando update()
                } catch (\Exception $e) {
                    Log::error('Error al actualizar suscripción en Stripe: ' . $e->getMessage());
                }
            }
            */
        }
    }

    protected function handleCheckoutCompleted($session)
    {
        $subscription = Subscription::findOrFail($session->client_reference_id);

        $subscription->update([
            'status' => 'active',
            'stripe_subscription_id' => $session->subscription,
            'paid_at' => now(),
            'stripe_status' => 'active'
        ]);

        // Notificar al usuario
        $subscription->organization->notify(new SubscriptionActivated($subscription));
    }


}