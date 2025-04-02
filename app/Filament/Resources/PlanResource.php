<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PlanResource\Pages;
use App\Filament\Resources\PlanResource\RelationManagers;
use App\Models\Plan;
use Filament\Forms;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Actions\Action;
use Filament\Tables\Columns\BadgeColumn;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class PlanResource extends Resource
{
    protected static ?string $model = Plan::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';
    
    protected static ?string $navigationGroup = 'Suscripciones';
    
    protected static ?int $navigationSort = 10;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('Información del Plan')
                    ->schema([
                        Grid::make([
                            'sm' => 1,
                            'md' => 2,
                        ])
                        ->schema([
                            TextInput::make('name')
                                ->label('Nombre')
                                ->required()
                                ->maxLength(255)
                                ->columnSpan(1),
                                
                            TextInput::make('slug')
                                ->label('Slug')
                                ->required()
                                ->unique(ignoreRecord: true)
                                ->maxLength(255)
                                ->columnSpan(1),
                        ]),
                        
                        Textarea::make('description')
                            ->label('Descripción')
                            ->rows(3)
                            ->maxLength(500),
                            
                        Grid::make([
                            'sm' => 1,
                            'md' => 3,
                        ])
                        ->schema([
                            Toggle::make('is_active')
                                ->label('Activo')
                                ->default(true)
                                ->onIcon('heroicon-s-check')
                                ->offIcon('heroicon-s-x-mark'),
                                
                            Toggle::make('is_default')
                                ->label('Plan por defecto')
                                ->default(false)
                                ->onIcon('heroicon-s-check')
                                ->offIcon('heroicon-s-x-mark'),
                                
                            TextInput::make('trial_days')
                                ->label('Días de prueba')
                                ->numeric()
                                ->default(0)
                                ->minValue(0)
                                ->maxValue(365),
                        ]),
                    ]),
                    
                Section::make('Integración con Stripe')
                    ->schema([
                        TextInput::make('stripe_product_id')
                            ->label('ID del Producto en Stripe')
                            ->helperText('Se generará automáticamente si lo dejas en blanco')
                            ->maxLength(255),
                            
                        Placeholder::make('stripe_sync')
                            ->label('Sincronización')
                            ->content('Después de guardar, puedes sincronizar este plan con Stripe desde la lista de planes.')
                            ->hidden(fn ($record) => $record === null),
                    ])
                    ->collapsible(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Nombre')
                    ->searchable()
                    ->sortable(),
                    
                TextColumn::make('is_active')
                    ->label('Estado')
                    ->badge()
                    ->color(fn (bool $state): string => $state ? 'success' : 'gray')
                    ->formatStateUsing(fn (bool $state): string => $state ? 'Active' : 'Inactive'),
                    
                IconColumn::make('is_default')
                    ->label('Por defecto')
                    ->boolean()
                    ->trueColor('primary')
                    ->falseColor('gray'),
                    
                TextColumn::make('trial_days')
                    ->label('Días de prueba')
                    ->sortable(),
                    
                TextColumn::make('prices.price')
                    ->label('Precio base')
                    ->getStateUsing(function (Plan $record) {
                        $defaultPrice = $record->getDefaultPrice();
                        if ($defaultPrice) {
                            return $defaultPrice->formatted_price;
                        }
                        return '-';
                    }),
                    
                TextColumn::make('stripe_product_id')
                    ->label('Stripe ID')
                    ->toggleable(isToggledHiddenByDefault: true),
                    
                TextColumn::make('created_at')
                    ->label('Creado')
                    ->dateTime('d/m/Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Estado')
                    ->options([
                        '1' => 'Activo',
                        '0' => 'Inactivo',
                    ])
                    ->attribute('is_active'),
                    
                Tables\Filters\TernaryFilter::make('is_default')
                    ->label('Plan por defecto'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Action::make('syncStripe')
                    ->label('Sincronizar con Stripe')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->action(function (Plan $record) {
                        if ($record->syncWithStripe()) {
                            \Filament\Notifications\Notification::make()
                                ->title('Plan sincronizado')
                                ->body('El plan ha sido sincronizado correctamente con Stripe.')
                                ->success()
                                ->send();
                        } else {
                            \Filament\Notifications\Notification::make()
                                ->title('Error de sincronización')
                                ->body('No se pudo sincronizar el plan con Stripe.')
                                ->danger()
                                ->send();
                        }
                    })
                    ->requiresConfirmation()
                    ->modalHeading('¿Sincronizar con Stripe?')
                    ->modalSubheading('Esto creará o actualizará este plan en Stripe.')
                    ->modalButton('Sincronizar'),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),

                    Tables\Actions\BulkAction::make('activatePlans')
                    ->label('Activar planes')
                    ->icon('heroicon-o-check')
                    ->color('success')
                    ->action(function (Collection $records) {
                        $records->each(function ($record) {
                            $record->is_active = true;
                            $record->save();
                        });
                        
                        \Filament\Notifications\Notification::make()
                            ->title('Planes activados')
                            ->success()
                            ->send();
                    })
                    ->requiresConfirmation()
                    ->deselectRecordsAfterCompletion(),
                    
                Tables\Actions\BulkAction::make('deactivatePlans')
                    ->label('Desactivar planes')
                    ->icon('heroicon-o-x-mark')
                    ->color('danger')
                    ->action(function (Collection $records) {
                        $records->each(function ($record) {
                            $record->is_active = false;
                            $record->save();
                        });
                        
                        \Filament\Notifications\Notification::make()
                            ->title('Planes desactivados')
                            ->success()
                            ->send();
                    })
                    ->requiresConfirmation()
                    ->deselectRecordsAfterCompletion(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\PricesRelationManager::class,
            RelationManagers\FeaturesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPlans::route('/'),
            'create' => Pages\CreatePlan::route('/create'),
            'edit' => Pages\EditPlan::route('/{record}/edit'),
        ];
    }
}
