<?php

namespace App\Filament\Resources\PlanResource\RelationManagers;

use App\Models\Country;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Nnjeim\World\Models\Currency;

class PricesRelationManager extends RelationManager
{
    protected static string $relationship = 'prices';

    protected static ?string $recordTitleAttribute = 'country_code';

    protected static ?string $title = 'Precios por país';
    
    protected static ?string $label = 'Precio';
    
    protected static ?string $pluralLabel = 'Precios';

    public function form(Form $form): Form
    {
        // Get list of countries for dropdown
        $countries = Country::where('status', 1)
            ->orderBy('name')
            ->get()
            ->pluck('name', 'iso2')
            ->toArray();
            
        return $form
            ->schema([
                Forms\Components\Section::make()
                    ->schema([
                        Forms\Components\Select::make('country_code')
                            ->label('Country')
                            ->options($countries)
                            ->required()
                            ->searchable()
                            ->reactive()
                            ->afterStateUpdated(function (callable $set, $state) {
                                // Obtener la moneda del país seleccionado
                                $country = Country::where('iso2', $state)->first();
                                if ($country) {
                                    // Asumiendo que hay una relación o un modelo Currency
                                    $currency = Currency::where('country_id', $country->id)->first();
                                    if ($currency) {
                                        $set('currency', $currency->code);
                                    } else {
                                        // Default a USD si no hay moneda asociada
                                        $set('currency', 'USD');
                                    }
                                }
                            }),
                        Forms\Components\TextInput::make('price')
                            ->label('Base Price (USD)')
                            ->required()
                            ->numeric()
                            ->minValue(0)
                            ->step(0.01)
                            ->helperText('Enter the base price in USD. This is used for all plan types.'),
                        Forms\Components\Select::make('currency')
                            ->label('Display Currency')
                            ->options(function () {
                                // Obtener todas las monedas disponibles
                                return Currency::orderBy('name')
                                    ->get()
                                    ->pluck('name', 'code')
                                    ->toArray();
                            })
                            ->required()
                            ->default('USD')
                            ->dehydrated(true) // Asegurar que siempre se envíe al servidor incluso si está deshabilitado
                            ->afterStateHydrated(function ($component, $state) {
                                // Si la moneda es nula, establecer USD por defecto
                                if (!$state) {
                                    $component->state('USD');
                                }
                            })
                            ->helperText('Currency is automatically selected based on country.'),
                        Forms\Components\Select::make('billing_interval')
                            ->label('Billing Type')
                            ->options([
                                'monthly' => 'Monthly (no contract)',
                                'monthly_annual' => 'Monthly with annual contract (15% discount)',
                                'annual' => 'Annual payment (30% discount)',
                            ])
                            ->default('monthly')
                            ->required()
                            ->reactive()
                            ->afterStateUpdated(function (callable $set, $state, $get) {
                                // Establecer el porcentaje de descuento según el tipo de facturación
                                $basePrice = (float)$get('price') ?: 0;
                                
                                switch ($state) {
                                    case 'monthly_annual':
                                        $set('discount_percentage', 15);
                                        $set('original_price', $basePrice);
                                        $discountedPrice = $basePrice * 0.85;
                                        $set('price', round($discountedPrice, 2));
                                        break;
                                    case 'annual':
                                        $set('discount_percentage', 30);
                                        $set('original_price', $basePrice * 12);
                                        $discountedPrice = $basePrice * 12 * 0.7;
                                        $set('price', round($discountedPrice, 2));
                                        break;
                                    default: // monthly
                                        $set('discount_percentage', 0);
                                        $set('original_price', null);
                                        break;
                                }
                            }),
                        Forms\Components\TextInput::make('discount_percentage')
                            ->label('Discount (%)')
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(100)
                            ->default(0)
                            ->step(1)
                            ->disabled()
                            ->helperText('Set automatically based on billing type.'),
                        Forms\Components\TextInput::make('discount_percentage')
                            ->label('Discount (%)')
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(100)
                            ->default(0)
                            ->step(1)
                            ->disabled()
                            ->helperText('Set automatically based on billing type.'),
                        Forms\Components\TextInput::make('stripe_price_id')
                            ->label('Stripe Price ID')
                            ->helperText('Will be generated automatically when syncing with Stripe')
                            ->columnSpan('full'),
                    ])->columns(2),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('country_code')
                    ->label('Country')
                    ->formatStateUsing(function ($state) {
                        $country = Country::where('iso2', $state)->first();
                        if ($country) {
                            return "{$country->emoji} {$country->name}";
                        }
                        return $state;
                    })
                    ->searchable(),
                Tables\Columns\TextColumn::make('billing_interval')
                    ->label('Plan Type')
                    ->formatStateUsing(function ($state) {
                        switch ($state) {
                            case 'monthly_annual':
                                return 'Monthly with annual contract';
                            case 'annual':
                                return 'Annual payment';
                            default:
                                return 'Monthly (no contract)';
                        }
                    }),
                Tables\Columns\TextColumn::make('formatted_price')
                    ->label('Price')
                    ->formatStateUsing(function ($state, $record) {
                        $currencySymbol = '$'; // Default para USD
                        
                        // Intentar obtener el símbolo de la moneda
                        if ($record->currency) {
                            $currency = Currency::where('code', $record->currency)->first();
                            if ($currency) {
                                $currencySymbol = $currency->symbol;
                            }
                        }
                        
                        return $currencySymbol . ' ' . number_format($record->price, 2);
                    }),
                Tables\Columns\TextColumn::make('discount_percentage')
                    ->label('Discount')
                    ->formatStateUsing(fn ($state) => $state > 0 ? "{$state}%" : '-'),
                Tables\Columns\TextColumn::make('original_price')
                    ->label('Original Price')
                    ->formatStateUsing(function ($state, $record) {
                        if (!$state) return '-';
                        
                        $currencySymbol = '$'; // Default para USD
                        
                        // Intentar obtener el símbolo de la moneda
                        if ($record->currency) {
                            $currency = Currency::where('code', $record->currency)->first();
                            if ($currency) {
                                $currencySymbol = $currency->symbol;
                            }
                        }
                        
                        return $currencySymbol . ' ' . number_format($state, 2);
                    })
                    ->toggleable(),
                Tables\Columns\TextColumn::make('currency')
                    ->label('Currency')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('stripe_price_id')
                    ->label('Stripe Price ID')
                    ->limit(20)
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('country_code')
                    ->label('Country')
                    ->options(function () {
                        return Country::where('status', 1)
                            ->orderBy('name')
                            ->get()
                            ->pluck('name', 'iso2')
                            ->toArray();
                    })
                    ->searchable(),
                Tables\Filters\SelectFilter::make('billing_interval')
                    ->label('Billing Type')
                    ->options([
                        'monthly' => 'Monthly (no contract)',
                        'monthly_annual' => 'Monthly with annual contract',
                        'annual' => 'Annual payment',
                    ]),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->slideOver(),
                    Tables\Actions\Action::make('createAllPrices')
                        ->label('Create All Price Types')
                        ->slideOver()
                        ->color('warning')
                        ->icon('heroicon-m-plus-circle')
                        ->form([
                            Forms\Components\Select::make('country_code')
                                ->label('Country')
                                ->options(function () {
                                    return Country::where('status', 1)
                                        ->orderBy('name')
                                        ->get()
                                        ->pluck('name', 'iso2')
                                        ->toArray();
                                })
                                ->required()
                                ->searchable()
                                ->reactive()
                                ->afterStateUpdated(function (callable $set, $state) {
                                    $country = Country::where('iso2', $state)->first();
                                    if ($country) {
                                        $currency = Currency::where('country_id', $country->id)->first();
                                        if ($currency) {
                                            $set('currency', $currency->code);
                                        } else {
                                            $set('currency', 'USD');
                                        }
                                    }
                                }),
                            Forms\Components\TextInput::make('base_price')
                                ->label('Base Monthly Price (USD)')
                                ->required()
                                ->numeric()
                                ->minValue(0)
                                ->step(0.01)
                                ->helperText('The base monthly price without discounts. Other prices will be calculated automatically.'),
                            Forms\Components\Select::make('currency')
                                ->label('Display Currency')
                                ->options(function () {
                                    return Currency::orderBy('name')
                                        ->get()
                                        ->pluck('name', 'code')
                                        ->toArray();
                                })
                                ->required()
                                ->default('USD')
                                ->dehydrated(true)
                                ->helperText('Currency for display purposes.'),
                        ])
                        ->action(function (array $data, RelationManager $livewire): void {
                            $plan = $livewire->getOwnerRecord();
                            $plan->createAllPricesForCountry(
                                $data['country_code'],
                                $data['base_price'],
                                $data['currency'] ?? 'USD'
                            );
                            
                            // Notificar al usuario
                            Notification::make()
                                ->title('Prices created successfully')
                                ->body('All price types have been created for ' . $data['country_code'])
                                ->success()
                                ->send();
                        }),
                //Tables\Actions\AttachAction::make(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DetachAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DetachBulkAction::make(),
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    protected static function getCountriesOptions(): array
    {
        return [
            'US' => 'Estados Unidos (US)',
            'MX' => 'México (MX)',
            'CO' => 'Colombia (CO)',
            'AR' => 'Argentina (AR)',
            'CL' => 'Chile (CL)',
            'PE' => 'Perú (PE)',
            'BO' => 'Bolivia (BO)',
            'ES' => 'España (ES)',
            'BR' => 'Brasil (BR)',
            'VE' => 'Venezuela (VE)',
            'EC' => 'Ecuador (EC)',
            'UY' => 'Uruguay (UY)',
            'PY' => 'Paraguay (PY)',
            'DO' => 'República Dominicana (DO)',
            'GT' => 'Guatemala (GT)',
            'CR' => 'Costa Rica (CR)',
            'PA' => 'Panamá (PA)',
            'SV' => 'El Salvador (SV)',
            'HN' => 'Honduras (HN)',
            'NI' => 'Nicaragua (NI)',
            'CU' => 'Cuba (CU)',
            // Añade más países según necesites
        ];
    }
    
    protected static function getCountryName(string $code): string
    {
        $countries = self::getCountriesOptions();
        
        if (isset($countries[$code])) {
            return explode(' (', $countries[$code])[0];
        }
        
        return $code;
    }
}
