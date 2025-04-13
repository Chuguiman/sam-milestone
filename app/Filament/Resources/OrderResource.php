<?php

namespace App\Filament\Resources;

use App\Filament\Resources\OrderResource\Pages;
use App\Filament\Resources\OrderResource\RelationManagers;
use App\Models\Order;
use App\Models\Subscription;
use App\Services\StripeCheckoutService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Stripe\Stripe;
use Stripe\Checkout\Session;

class OrderResource extends Resource
{
    protected static ?string $model = Order::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?string $navigationGroup = 'Suscripciones';
    
    protected static ?int $navigationSort = 3;
    
    protected static ?string $recordTitleAttribute = 'id';
    
    protected static ?string $modelLabel = 'Orden';
    
    protected static ?string $pluralModelLabel = 'Ordenes';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Información de la Orden')
                ->schema([

                    Forms\Components\Select::make('subscription_id')
                        ->relationship('subscription', 'id')
                        ->required()
                        ->reactive()
                        ->afterStateUpdated(function (Forms\Set $set, Forms\Get $get, $state) {
                            if (!$state) return;
                            
                            // Buscar la suscripción
                            $subscription = \App\Models\Subscription::with(['organization', 'plan', 'discount'])
                                ->find($state);
                                
                            if (!$subscription) return;
                            
                            // Cargar datos básicos
                            $set('organization_id', $subscription->organization_id);
                            $set('plan_id', $subscription->plan_id);
                            $set('status', 'pending');
                            $set('billing_interval', $subscription->billing_interval);
                            
                            // Obtener precio del plan desde la tabla plan_price_by_countries
                            $countryCode = $subscription->organization->country_code ?? 'US';
                            $planPrice = DB::table('plan_price_by_countries')
                                ->where('plan_id', $subscription->plan_id)
                                ->where('country_code', $countryCode)
                                ->where('billing_interval', $subscription->billing_interval)
                                ->first();
                            
                            if (!$planPrice) {
                                // Si no hay precio específico, buscar cualquier precio para este plan
                                $planPrice = DB::table('plan_price_by_countries')
                                    ->where('plan_id', $subscription->plan_id)
                                    ->first();
                            }
                            
                            // Calcular detalles financieros
                            $subtotal = $planPrice ? $planPrice->price : 0;
                            
                            // Calcular descuento
                            $discount = 0;
                            if ($subscription->discount_id && $subscription->discount) {
                                $discountModel = $subscription->discount;
                                $discount = match($discountModel->type) {
                                    'percentage' => $subtotal * ($discountModel->value / 100),
                                    'fixed' => $discountModel->value,
                                    default => 0
                                };
                            }
                            
                            // Calcular impuestos
                            $tax = 0;
                            if ($subscription->is_taxable && $subscription->organization && $subscription->organization->country) {
                                $taxRate = $subscription->organization->country->tax_rate ?? 0;
                                $tax = $subtotal * ($taxRate / 100);
                            }
                            
                            // Calcular total
                            $totalAmount = $subtotal - $discount + $tax;
                            
                            // Establecer valores financieros
                            $set('subtotal', $subtotal);
                            $set('discount', $discount);
                            $set('tax', $tax);
                            $set('total_amount', $totalAmount);
                            
                            // Establecer moneda
                            $set('currency', $planPrice ? $planPrice->currency : 'USD');
                            
                            // Agregar add-ons si existen
                            $addOnsTotal = 0;
                            foreach ($subscription->addOns as $addOn) {
                                $addOnsTotal += $addOn->pivot->price * $addOn->pivot->quantity;
                            }
                            
                            if ($addOnsTotal > 0) {
                                // Actualizar subtotal y total
                                $newSubtotal = $subtotal + $addOnsTotal;
                                $set('subtotal', $newSubtotal);
                                $set('total_amount', $newSubtotal - $discount + $tax);
                            }
                        }),

                Forms\Components\Select::make('organization_id')
                    ->relationship('organization', 'name')
                    ->disabled(),

                Forms\Components\Select::make('plan_id')
                    ->relationship('plan', 'name')
                    ->disabled(),

                Forms\Components\Select::make('status')
                    ->options([
                        'pending' => 'Pendiente',
                        'paid' => 'Pagado',
                        'failed' => 'Fallido',
                        'cancelled' => 'Cancelado',
                        'refunded' => 'Reembolsado'
                    ])
                    ->required(),
            ])->columns(4),

        Forms\Components\Section::make('Detalles Financieros')
            ->schema([
                Forms\Components\TextInput::make('subtotal')
                    ->numeric()
                    ->prefix('$')
                    ->required(),
                
                Forms\Components\TextInput::make('discount')
                    ->numeric()
                    ->prefix('$')
                    ->default(0),
                
                Forms\Components\TextInput::make('tax')
                    ->numeric()
                    ->prefix('$')
                    ->default(0),
                
                Forms\Components\TextInput::make('total_amount')
                    ->numeric()
                    ->prefix('$')
                    ->required(),
            ])->columns(4),
                

            Forms\Components\Section::make('Información de Stripe')
                ->schema([
                    Forms\Components\TextInput::make('stripe_session_id')
                        ->label('ID de Sesión de Stripe')
                        ->suffixAction(
                            Forms\Components\Actions\Action::make('fetch_stripe_details')
                                ->icon('heroicon-o-arrow-down-tray')
                                ->action(function (Forms\Set $set, $state) {
                                    try {
                                        $order = Order::where('stripe_session_id', $state)->first();
                                        if ($order) {
                                            $success = $order->syncWithStripe();
                                            
                                            if ($success) {
                                                Notification::make()
                                                    ->title('Sincronización Exitosa')
                                                    ->body('Detalles actualizados desde Stripe')
                                                    ->success()
                                                    ->send();
                                            } else {
                                                Notification::make()
                                                    ->title('Error de Sincronización')
                                                    ->body('No se pudieron recuperar los detalles')
                                                    ->danger()
                                                    ->send();
                                            }
                                        }
                                    } catch (\Exception $e) {
                                        Notification::make()
                                            ->title('Error')
                                            ->body($e->getMessage())
                                            ->danger()
                                            ->send();
                                    }
                                })
                        ),
                    
                    Forms\Components\TextInput::make('stripe_checkout_url')
                        ->label('URL de Checkout')
                        ->columnSpan(2),
                ])->columns(3),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('subscription.id')
                    ->label('Suscripción ID')
                    ->sortable(),

                Tables\Columns\TextColumn::make('organization.name')
                    ->label('Organización')
                    ->searchable(),
                
                Tables\Columns\TextColumn::make('plan.name')
                    ->label('Plan')
                    ->searchable(),
                
                Tables\Columns\SelectColumn::make('status')
                    ->options([
                        'pending' => 'Pendiente',
                        'paid' => 'Pagado',
                        'failed' => 'Fallido',
                        'cancelled' => 'Cancelado',
                        'refunded' => 'Reembolsado'
                    ])
                    ->searchable(),
                
                Tables\Columns\TextColumn::make('total_amount')
                    ->label('Total')
                    ->money('USD')
                    ->sortable(),
                
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Fecha de Orden')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'pending' => 'Pendiente',
                        'paid' => 'Pagado',
                        'failed' => 'Fallido',
                        'cancelled' => 'Cancelado',
                        'refunded' => 'Reembolsado'
                    ])
            ])
            ->actions([
                Tables\Actions\Action::make('set_stripe_session')
                    ->label('Asignar ID Sesión Stripe')
                    ->icon('heroicon-o-link')
                    ->form([
                        Forms\Components\TextInput::make('stripe_session_id')
                            ->label('ID de Sesión de Stripe')
                            ->required()
                            ->placeholder('cs_test_...')
                            ->helperText('Ingresa el ID de sesión de Stripe para sincronizar esta orden'),
                    ])
                    ->action(function (Order $record, array $data) {
                        try {
                            // Actualizar el ID de sesión
                            $record->update(['stripe_session_id' => $data['stripe_session_id']]);
                            
                            // Intentar sincronizar con Stripe
                            $success = $record->syncWithStripe();
                            
                            if ($success) {
                                Notification::make()
                                    ->title('Sincronización Exitosa')
                                    ->body('Orden actualizada con datos de Stripe')
                                    ->success()
                                    ->send();
                            } else {
                                Notification::make()
                                    ->title('Error de Sincronización')
                                    ->body('ID de sesión guardado pero no se pudo sincronizar')
                                    ->warning()
                                    ->send();
                            }
                        } catch (\Exception $e) {
                            Notification::make()
                                ->title('Error')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
                Tables\Actions\Action::make('create_stripe_order')
                    ->label('Crear Orden con Stripe')
                    ->icon('heroicon-o-credit-card')
                    ->form([
                        Forms\Components\Select::make('subscription_id')
                            ->label('Suscripción')
                            ->relationship('subscription', 'id')
                            ->searchable()
                            ->required()
                            ->reactive()
                            ->afterStateUpdated(function (Forms\Set $set, $state) {
                                if ($state) {
                                    $subscription = \App\Models\Subscription::find($state);
                                    if ($subscription) {
                                        $set('organization_id', $subscription->organization_id);
                                        $set('plan_id', $subscription->plan_id);
                                    }
                                }
                            }),
                        
                        Forms\Components\Select::make('organization_id')
                            ->label('Organización')
                            ->relationship('organization', 'name')
                            ->disabled(),
                        
                        Forms\Components\Select::make('plan_id')
                            ->label('Plan')
                            ->relationship('plan', 'name')
                            ->disabled(),
                        Forms\Components\Section::make('Detalles Financieros')
                            ->schema([
                                Forms\Components\TextInput::make('subtotal')
                                    ->numeric()
                                    ->prefix('$')
                                    ->required(),
                                
                                Forms\Components\TextInput::make('discount')
                                    ->numeric()
                                    ->prefix('$')
                                    ->default(0),
                                
                                Forms\Components\TextInput::make('tax')
                                    ->numeric()
                                    ->prefix('$')
                                    ->default(0),
                                
                                Forms\Components\TextInput::make('total_amount')
                                    ->numeric()
                                    ->prefix('$')
                                    ->required(),
                            ])->columns(4),
                        
                    ])
                    ->action(function (array $data) {
                        try {
                            // Crear la orden
                            $order = Order::create($data);
                            
                            // Obtener la suscripción
                            $subscription = Subscription::find($data['subscription_id']);
                            
                            // Crear sesión de Stripe
                            $stripeService = new \App\Services\StripeCheckoutService();
                            $checkoutUrl = $stripeService->createCheckoutSession($subscription);

                            // Extraer el ID de sesión desde la URL (si es posible)
                            $sessionId = null;
                            if (preg_match('/cs_[a-zA-Z0-9_]+/', $checkoutUrl, $matches)) {
                                $sessionId = $matches[0];
                            }

                            // Actualizar la orden con la información de la sesión
                            $order->update([
                                'stripe_session_id' => $sessionId,
                                'stripe_checkout_url' => $checkoutUrl,
                            ]);

                            Notification::make()
                                ->title('Orden Creada')
                                ->body('Se ha creado una nueva orden con sesión de Stripe')
                                ->success()
                                ->actions([
                                    \Filament\Notifications\Actions\Action::make('checkout')
                                        ->label('Ir a Checkout')
                                        ->url($checkoutUrl)
                                        ->openUrlInNewTab(),
                                ])
                                ->send();
                                
                            return $order;
                        } catch (\Exception $e) {
                            Notification::make()
                                ->title('Error')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
                Tables\Actions\Action::make('sync_stripe')
                    ->label('Sincronizar con Stripe')
                    ->icon('heroicon-o-arrow-path')
                    ->action(function (Order $record) {
                        try {
                            $success = $record->syncWithStripe();
                            
                            if ($success) {
                                Notification::make()
                                    ->title('Sincronización Exitosa')
                                    ->body('Detalles actualizados desde Stripe')
                                    ->success()
                                    ->send();
                            } else {
                                Notification::make()
                                    ->title('Error de Sincronización')
                                    ->body('No se pudieron recuperar los detalles')
                                    ->danger()
                                    ->send();
                            }
                        } catch (\Exception $e) {
                            Notification::make()
                                ->title('Error')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
                    // Mostrar solo para órdenes con session_id
                    //->visible(fn (Order $record) => $record->stripe_session_id),
                
                Tables\Actions\EditAction::make(),
                /* Tables\Actions\Action::make('create_manual_order')
                    ->label('Crear Orden Manual')
                    ->icon('heroicon-o-plus-circle')
                    ->form([
                        Forms\Components\Select::make('subscription_id')
                            ->label('Suscripción')
                            ->relationship('subscription', 'id')
                            ->searchable()
                            ->required()
                            ->reactive()
                            ->afterStateUpdated(function (Forms\Set $set, $state) {
                                if ($state) {
                                    $subscription = \App\Models\Subscription::find($state);
                                    if ($subscription) {
                                        $set('organization_id', $subscription->organization_id);
                                        $set('plan_id', $subscription->plan_id);
                                    }
                                }
                            }),
                        
                        Forms\Components\Select::make('organization_id')
                            ->label('Organización')
                            ->relationship('organization', 'name')
                            ->disabled(),
                        
                        Forms\Components\Select::make('plan_id')
                            ->label('Plan')
                            ->relationship('plan', 'name')
                            ->disabled(),
                        
                        Forms\Components\TextInput::make('stripe_session_id')
                            ->label('ID de Sesión de Stripe (opcional)')
                            ->helperText('Si lo proporciona, se sincronizarán los datos desde Stripe'),
                    ])
                    ->action(function (array $data) {
                        try {
                            $subscription = \App\Models\Subscription::findOrFail($data['subscription_id']);
                            
                            // Crear una nueva orden para esta suscripción
                            $order = Order::createForSubscription($subscription);
                            
                            // Si se proporcionó un ID de sesión de Stripe, sincronizar
                            if (!empty($data['stripe_session_id'])) {
                                $order->update(['stripe_session_id' => $data['stripe_session_id']]);
                                $success = $order->syncWithStripe();
                                
                                if (!$success) {
                                    throw new \Exception('No se pudieron recuperar los datos de Stripe');
                                }
                            }
                            
                            Notification::make()
                                ->title('Orden Creada')
                                ->body('La orden se ha creado correctamente')
                                ->success()
                                ->send();
                                
                        } catch (\Exception $e) {
                            Notification::make()
                                ->title('Error')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),     */
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
                Tables\Actions\BulkAction::make('sync_stripe_bulk_comprehensive')
                    ->label('Sincronizar Órdenes Pendientes')
                    ->icon('heroicon-o-arrow-path')
                    ->action(function ($records) {
                        $successCount = 0;
                        $errorCount = 0;
                        $skippedCount = 0;

                        Stripe::setApiKey(config('services.stripe.secret'));

                        foreach ($records as $record) {
                            try {
                                // Verificar si hay ID de sesión
                                if (empty($record->stripe_session_id)) {
                                    $skippedCount++;
                                    continue;
                                }
                                
                                $success = $record->syncWithStripe();
                                
                                if ($success) {
                                    $successCount++;
                                } else {
                                    $errorCount++;
                                }
                            } catch (\Exception $e) {
                                $errorCount++;
                                Log::error('Bulk Sync Stripe Error', [
                                    'order_id' => $record->id,
                                    'error' => $e->getMessage()
                                ]);
                            }
                        }

                        $message = "Completado. {$successCount} éxitos, {$errorCount} errores";
                        if ($skippedCount > 0) {
                            $message .= ", {$skippedCount} omitidos (sin ID de sesión)";
                        }

                        Notification::make()
                            ->title('Sincronización de Órdenes')
                            ->body($message)
                            ->status($successCount > 0 ? 'success' : 'warning')
                            ->send();
                    })
                    ->deselectRecordsAfterCompletion()
                    ->requiresConfirmation(),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListOrders::route('/'),
            'create' => Pages\CreateOrder::route('/create'),
            'edit' => Pages\EditOrder::route('/{record}/edit'),
        ];
    }

    public static function mutateFormDataBeforeCreate(array $data): array
    {
        // Verificar si hay datos de suscripción pero faltan los de organización y plan
        if (!empty($data['subscription_id']) && (empty($data['organization_id']) || empty($data['plan_id']))) {
            $subscription = Subscription::find($data['subscription_id']);
            if ($subscription) {
                // Asegurarse de que se incluyan estos campos
                $data['organization_id'] = $subscription->organization_id;
                $data['plan_id'] = $subscription->plan_id;
                
                // Si también falta billing_interval, agregarlo
                if (empty($data['billing_interval'])) {
                    $data['billing_interval'] = $subscription->billing_interval;
                }
            }
        }
        
        // Si no hay fecha de pago y el estado es 'paid', establecer la fecha actual
        if (($data['status'] ?? '') === 'paid' && empty($data['paid_at'])) {
            $data['paid_at'] = now();
        }
        
        return $data;
    }
}
