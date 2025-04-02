<?php

namespace App\Filament\Cliente\Pages;

use App\Models\AddOn;
use App\Models\City;
use App\Models\Country;
use App\Models\Discount;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\PlanPriceByCountry;
use App\Models\State;
use App\Models\Subscription;
use App\Services\SubscriptionService;
use Filament\Forms\Components\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Fieldset;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Group;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ViewField;
use Filament\Forms\Components\Wizard;
use Filament\Forms\Components\Wizard\Step;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Nnjeim\World\Models\Currency;

class OrganizationSetupWizard extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-building-office';
    protected static string $view = 'filament.cliente.pages.organization-setup-wizard';
    protected static ?string $title = 'Configuración de tu organización';
    protected ?string $heading = 'Configura tu organización';
    protected ?string $subheading = 'Completa la información necesaria para empezar a usar la plataforma';
    
    public ?array $data = [];
    
    public function mount(): void
    {
        // Verificar si el usuario ya tiene organizaciones
        $hasOrganizations = Auth::user()->organizations()->exists();
        
        if ($hasOrganizations) {
            // Redirigir a dashboard si ya tiene organizaciones configuradas
            $this->redirect(route('filament.cliente.pages.dashboard'));
            return;
        }
        
        // Establecer valores iniciales si es necesario
        $this->form->fill([
            'country_id' => null,
            'state_id' => null,
            'city_id' => null,
            'billing_interval' => 'monthly',
        ]);
    }
    
    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Wizard::make([
                    // Paso 1: Información de la organización
                    Step::make('Información de la organización')
                        ->icon('heroicon-m-building-office-2')
                        ->description('Datos básicos de tu organización')
                        ->schema([
                            Section::make()
                                ->schema([
                                    Grid::make(2)
                                        ->schema([
                                            FileUpload::make('avatar')
                                                ->label('Logo de la organización')
                                                ->avatar()
                                                ->disk('public')
                                                ->directory('organizations/avatars')
                                                ->image()
                                                ->maxSize(2048)
                                                ->visibility('public')
                                                ->columnSpan(1),
                                                
                                            Group::make()
                                                ->schema([
                                                    TextInput::make('name')
                                                        ->label('Nombre de la organización')
                                                        ->required()
                                                        ->maxLength(255)
                                                        ->live(debounce: 500)
                                                        ->afterStateUpdated(function (string $state, callable $set) {
                                                            $set('slug', Str::slug($state));
                                                        }),
                                                        
                                                    TextInput::make('slug')
                                                        ->label('URL de la organización')
                                                        ->required()
                                                        ->unique(Organization::class, 'slug')
                                                        ->maxLength(255)
                                                        ->helperText('Esta será la URL para acceder a tu organización'),
                                                        
                                                    TextInput::make('support_email')
                                                        ->label('Email de soporte/contacto')
                                                        ->email()
                                                        ->maxLength(255),
                                                ])
                                                ->columnSpan(1),
                                        ]),
                                ]),
                                
                            Section::make('Ubicación')
                                ->schema([
                                    Grid::make(1)
                                        ->schema([
                                            TextInput::make('address')
                                                ->label('Dirección')
                                                ->maxLength(255),
                                        ]),
                                        
                                    Grid::make(3)
                                        ->schema([
                                            Select::make('country_id')
                                                ->label('País')
                                                ->relationship(name: 'country', titleAttribute: 'name')
                                                ->searchable()
                                                ->preload()
                                                ->required()
                                                ->live()
                                                ->afterStateUpdated(function (callable $set) {
                                                    $set('state_id', null);
                                                    $set('city_id', null);
                                                }),
                                                
                                            Select::make('state_id')
                                                ->label('Estado/Provincia')
                                                ->options(function ($get) {
                                                    $countryId = $get('country_id');
                                                    
                                                    if (!$countryId) {
                                                        return [];
                                                    }
                                                    
                                                    return State::query()
                                                        ->where('country_id', $countryId)
                                                        ->orderBy('name')
                                                        ->pluck('name', 'id')
                                                        ->toArray();
                                                })
                                                ->searchable()
                                                ->preload()
                                                ->required()
                                                ->live()
                                                ->afterStateUpdated(fn ($set) => $set('city_id', null))
                                                ->visible(fn ($get) => (bool) $get('country_id')),
                                                
                                            Select::make('city_id')
                                                ->label('Ciudad')
                                                ->options(function ($get) {
                                                    $stateId = $get('state_id');
                                                    
                                                    if (!$stateId) {
                                                        return [];
                                                    }
                                                    
                                                    return City::query()
                                                        ->where('state_id', $stateId)
                                                        ->orderBy('name')
                                                        ->pluck('name', 'id')
                                                        ->toArray();
                                                })
                                                ->searchable()
                                                ->preload()
                                                ->required()
                                                ->visible(fn ($get) => (bool) $get('state_id')),
                                        ]),
                                        
                                    Grid::make(2)
                                        ->schema([
                                            TextInput::make('postcode')
                                                ->label('Código postal')
                                                ->maxLength(20),
                                                
                                            Select::make('currency')
                                                ->label('Moneda')
                                                ->options([
                                                    'USD' => 'USD - Dólar estadounidense',
                                                    'EUR' => 'EUR - Euro',
                                                    'GBP' => 'GBP - Libra esterlina',
                                                    'MXN' => 'MXN - Peso mexicano',
                                                    'COP' => 'COP - Peso colombiano',
                                                    'ARS' => 'ARS - Peso argentino',
                                                    'CLP' => 'CLP - Peso chileno',
                                                    'PEN' => 'PEN - Sol peruano',
                                                    'BOB' => 'BOB - Boliviano',
                                                    'BRL' => 'BRL - Real brasileño',
                                                ])
                                                ->default('USD')
                                                ->searchable(),
                                        ]),
                                ]),
                        ]),
                        
                    // Paso 2: Selección de plan
                    Step::make('Selecciona un plan')
                        ->icon('heroicon-m-credit-card')
                        ->description('Elige el plan que mejor se adapte a tus necesidades')
                        ->schema([
                            Section::make()
                                ->schema([
                                    Radio::make('plan_id')
                                        ->label('Selecciona el plan')
                                        ->options(function ($get) {
                                            $countryId = $get('country_id');
                                            if (!$countryId) return [];
                                            
                                            $country = Country::find($countryId);
                                            if (!$country) return [];
                                            
                                            // Obtener el código de país
                                            $countryCode = $country->iso2;
                                            
                                            // Obtener planes disponibles para este país
                                            $planIds = PlanPriceByCountry::where('country_code', $countryCode)
                                                ->pluck('plan_id')
                                                ->unique()
                                                ->toArray();
                                                
                                            // Obtener todos los planes activos que tienen precios para este país
                                            $plans = Plan::where('is_active', true)
                                                ->whereIn('id', $planIds)
                                                ->get();
                                            
                                            $options = [];
                                            
                                            foreach ($plans as $plan) {
                                                // Buscar el precio mensual (sin contrato)
                                                $monthlyPrice = PlanPriceByCountry::where('plan_id', $plan->id)
                                                    ->where('country_code', $countryCode)
                                                    ->where('billing_interval', 'monthly')
                                                    ->first();
                                                
                                                if (!$monthlyPrice) continue; // Si no hay precio mensual, saltar este plan
                                                
                                                // Buscar el precio mensual con contrato anual
                                                $monthlyAnnualPrice = PlanPriceByCountry::where('plan_id', $plan->id)
                                                    ->where('country_code', $countryCode)
                                                    ->where('billing_interval', 'monthly_annual')
                                                    ->first();
                                                
                                                // Buscar el precio de pago anual único
                                                $annualPrice = PlanPriceByCountry::where('plan_id', $plan->id)
                                                    ->where('country_code', $countryCode)
                                                    ->where('billing_interval', 'annual')
                                                    ->first();
                                                
                                                // Determinar la moneda según el país
                                                $currencyCode = $get('currency') ?? 'USD';
                                                $symbol = Currency::where('code', $currencyCode)->value('symbol') ?? $currencyCode;
                                                
                                                // Valores para la tabla
                                                $monthly = $monthlyPrice->price;
                                                $monthlyAnnual = $monthlyAnnualPrice ? $monthlyAnnualPrice->price : round($monthly * 0.85, 2);
                                                $annual = $annualPrice ? $annualPrice->price : round($monthly * 12 * 0.7, 2);
                                                
                                                // Calcular el equivalente mensual del plan anual
                                                $annualMonthly = round($annual / 12, 2);
                                                
                                                // Calcular el total anual para cada opción
                                                $monthlyTotal = $monthly * 12;
                                                $monthlyAnnualTotal = $monthlyAnnual * 12;
                                                
                                                // Construir la tabla HTML
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
                                                            <td class="p-2 text-right border dark:border-gray-600">' . $symbol . $monthlyTotal . '</td>
                                                            <td class="p-2 text-right border dark:border-gray-600">' . $symbol . $monthlyAnnualTotal . '</td>
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
                                        
                                    Placeholder::make('plan_features')
                                        ->label('Características incluidas')
                                        ->content(function ($get) {
                                            $planId = $get('plan_id');
                                            if (!$planId) return 'Selecciona un plan para ver sus características';
                                            
                                            $plan = Plan::find($planId);
                                            if (!$plan) return 'Plan no encontrado';
                                            
                                            // Obtener las características del plan
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
                                        })
                                        ->visible(fn ($get) => (bool) $get('plan_id')),
                                ]),
                                
                            Section::make('Opciones de Facturación')
                                ->schema([
                                    Radio::make('billing_interval')
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
                                        
                                    Select::make('discount_id')
                                        ->label('Código de descuento')
                                        ->relationship('discount', 'code', function ($query) {
                                            return $query->where('is_active', true)
                                                        ->where(function ($query) {
                                                            $query->whereNull('expires_at')
                                                                ->orWhere('expires_at', '>', now());
                                                        });
                                        })
                                        ->searchable()
                                        ->placeholder('Sin código de descuento')
                                        ->live(),
                                        
                                    Toggle::make('is_taxable')
                                        ->label('Requiero factura fiscal')
                                        ->helperText('Activa esta opción si necesitas factura con datos fiscales')
                                        ->default(false)
                                        ->live(),
                                ])
                                ->visible(fn ($get) => (bool) $get('plan_id')),
                        ]),
                        
                    // Paso 3: Complementos (Add-ons)
                    Step::make('Complementos opcionales')
                        ->icon('heroicon-m-puzzle-piece')
                        ->description('Personaliza tu suscripción con complementos adicionales')
                        ->schema([
                            Section::make()
                                ->schema([
                                    CheckboxList::make('addon_selections')
                                        ->label('Añadir complementos a tu suscripción')
                                        ->options(function ($get) {
                                            $countryId = $get('country_id');
                                            if (!$countryId) return [];
                                            
                                            $addons = AddOn::where('is_active', true)->get();
                                            $options = [];
                                            
                                            foreach ($addons as $addon) {
                                                $metadata = $addon->metadata ? json_decode($addon->metadata, true) : [];
                                                $unit = isset($metadata['unit']) ? " ({$metadata['unit']})" : '';
                                                
                                                $options[$addon->id] = $addon->name . $unit;
                                            }
                                            
                                            return $options;
                                        })
                                        ->descriptions(function ($get) {
                                            $countryId = $get('country_id');
                                            if (!$countryId) return [];
                                            
                                            $country = Country::find($countryId);
                                            if (!$country) return [];
                                            
                                            // Obtener el código de país
                                            $countryCode = $country->iso2;
                                            
                                            // Determinar moneda
                                            $currencyCode = $get('currency') ?? 'USD';
                                            $symbol = Currency::where('code', $currencyCode)->value('symbol') ?? $currencyCode;
                                            
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
                                        ->allowHtml(),
                                        
                                    // Campos ocultos para procesar los add-ons seleccionados
                                    Hidden::make('selected_addons_data')
                                        ->dehydrateStateUsing(function ($get) {
                                            $addonSelections = $get('addon_selections') ?? [];
                                            if (empty($addonSelections)) return json_encode([]);
                                            
                                            $addonData = [];
                                            
                                            foreach ($addonSelections as $addonId) {
                                                $addon = AddOn::find($addonId);
                                                if (!$addon) continue;
                                                
                                                $addonData[] = [
                                                    'addon_id' => $addonId,
                                                    'quantity' => 1, // Por defecto 1, se podría extender para permitir seleccionar cantidades
                                                    'price' => $addon->price,
                                                    'currency' => $get('currency') ?? 'USD',
                                                    'is_removable' => true,
                                                ];
                                            }
                                            
                                            return json_encode($addonData);
                                        }),
                                ]),
                        ]),
                        
                    // Paso 4: Resumen y pago
                    Step::make('Resumen y pago')
                        ->icon('heroicon-m-credit-card')
                        ->description('Revisa tu selección y procede al pago')
                        ->schema([
                            ViewField::make('subscription_summary')
                                ->view('filament.components.subscription-summary')
                                ->columnSpanFull(),
                                
                            Checkbox::make('accept_terms')
                                ->label('Acepto los términos y condiciones')
                                ->helperText(new \Illuminate\Support\HtmlString('Al hacer clic en "Crear mi organización y proceder al pago", aceptas nuestros <a href="#" class="text-primary-500 hover:text-primary-600 dark:text-primary-400" target="_blank">Términos de servicio</a> y <a href="#" class="text-primary-500 hover:text-primary-600 dark:text-primary-400" target="_blank">Política de privacidad</a>.'))
                                ->required(),
                        ]),
                ])
                ->skippable(false)
                ->persistStepInQueryString('step')
                /* ->submitAction(new Action())
                ->submitAction(
                    fn ($action) => $action
                        ->label('Crear mi organización y proceder al pago')
                        ->color('primary')
                        ->submit('submit')
                ), */
            ]);
    }
    
    public function submit(): void
    {
        // 1. Validar el formulario
        $data = $this->form->getState();
        
        // 2. Crear la organización
        $organization = new Organization();
        $organization->name = $data['name'];
        $organization->slug = $data['slug'];
        $organization->avatar = $data['avatar'] ?? null;
        $organization->support_email = $data['support_email'] ?? null;
        $organization->address = $data['address'] ?? null;
        $organization->city_id = $data['city_id'] ?? null;
        $organization->state_id = $data['state_id'] ?? null;
        $organization->country_id = $data['country_id'] ?? null;
        $organization->postcode = $data['postcode'] ?? null;
        $organization->currency = $data['currency'] ?? 'USD';
        $organization->status = 'pending'; // Comenzar como pendiente hasta que se complete el pago
        $organization->save();
        
        // 3. Establecer al usuario como administrador de la organización
        $user = Auth::user();
        $organization->addUserWithRole($user, 'admin');
        
        // 4. Crear la suscripción (inicialmente sin Stripe ID)
        $subscription = new Subscription();
        $subscription->name = 'default';
        $subscription->organization_id = $organization->id;
        $subscription->user_id = $user->id;
        $subscription->plan_id = $data['plan_id'];
        $subscription->billing_interval = $data['billing_interval'];
        $subscription->is_taxable = $data['is_taxable'] ?? false;
        $subscription->discount_id = $data['discount_id'] ?? null;
        $subscription->stripe_status = 'incomplete';
        $subscription->starts_at = now();
        $subscription->save();
        
        // 5. Guardar los add-ons seleccionados
        if (!empty($data['selected_addons_data'])) {
            $addons = json_decode($data['selected_addons_data'], true);
            
            foreach ($addons as $addon) {
                $subscription->addOns()->attach($addon['addon_id'], [
                    'quantity' => $addon['quantity'],
                    'price' => $addon['price'],
                ]);
            }
        }
        
        // 6. Crear checkout en Stripe y redirigir
        $checkoutUrl = app(SubscriptionService::class)->createCheckoutSession($subscription);
        
        if ($checkoutUrl) {
            // Guardar la organización ID en la sesión para poder acceder después
            session(['last_created_organization_id' => $organization->id]);
            
            // Redirigir a Stripe para completar el pago
            $this->redirect($checkoutUrl);
        } else {
            // Error al crear la sesión de pago
            Notification::make()
                ->title('Error al procesar el pago')
                ->body('No se pudo crear la sesión de pago. Por favor, inténtalo de nuevo más tarde.')
                ->danger()
                ->send();
        }
    }
}