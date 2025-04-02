<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AddOnResource\Pages;
use App\Filament\Resources\AddOnResource\RelationManagers;
use App\Models\AddOn;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class AddOnResource extends Resource
{
    protected static ?string $model = AddOn::class;

    protected static ?string $navigationIcon = 'heroicon-o-plus-circle';

    protected static ?string $navigationGroup = 'Suscripciones';
    
    protected static ?int $navigationSort = 30;
    
    protected static ?string $recordTitleAttribute = 'name';
    
    protected static ?string $modelLabel = 'Complemento';
    
    protected static ?string $pluralModelLabel = 'Complementos';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make()
                    ->schema([
                        Forms\Components\Grid::make([
                            'default' => 1,
                            'sm' => 2,
                        ])
                        ->schema([
                            Forms\Components\TextInput::make('name')
                                ->label('Nombre')
                                ->required()
                                ->maxLength(255)
                                ->columnSpan(1),
                                
                            Forms\Components\TextInput::make('code')
                                ->label('Código')
                                ->required()
                                ->unique(ignoreRecord: true)
                                ->maxLength(255)
                                ->columnSpan(1)
                                ->helperText('Identificador único para este complemento (sin espacios)'),
                        ]),
                        
                        Forms\Components\Textarea::make('description')
                            ->label('Descripción')
                            ->rows(3)
                            ->maxLength(500)
                            ->helperText('Descripción detallada de este complemento'),
                            
                        Forms\Components\Grid::make([
                            'default' => 1,
                            'sm' => 3,
                        ])
                        ->schema([
                            Forms\Components\TextInput::make('price')
                                ->label('Precio')
                                ->required()
                                ->numeric()
                                ->minValue(0)
                                ->step(0.01),
                                
                            Forms\Components\Select::make('currency')
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
                                ->searchable()
                                ->required()
                                ->default('USD'),
                                
                            Forms\Components\Toggle::make('is_active')
                                ->label('Activo')
                                ->default(true)
                                ->onIcon('heroicon-s-check')
                                ->offIcon('heroicon-s-x-mark'),
                        ]),
                    ]),
                    
                Forms\Components\Section::make('Integración con Stripe')
                    ->schema([
                        Forms\Components\TextInput::make('stripe_product_id')
                            ->label('ID del Producto en Stripe')
                            ->helperText('Se generará automáticamente si lo dejas en blanco')
                            ->maxLength(255),
                            
                        Forms\Components\TextInput::make('stripe_price_id')
                            ->label('ID del Precio en Stripe')
                            ->helperText('Se generará automáticamente si lo dejas en blanco')
                            ->maxLength(255),
                    ])
                    ->collapsible()
                    ->collapsed(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable(),
                Tables\Columns\TextColumn::make('code')
                    ->searchable(),
                Tables\Columns\TextColumn::make('price')
                    ->money()
                    ->sortable(),
                Tables\Columns\TextColumn::make('currency')
                    ->searchable(),
                Tables\Columns\TextColumn::make('stripe_product_id')
                    ->searchable(),
                Tables\Columns\TextColumn::make('stripe_price_id')
                    ->searchable(),
                Tables\Columns\IconColumn::make('is_active')
                    ->boolean(),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
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
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAddOns::route('/'),
            'create' => Pages\CreateAddOn::route('/create'),
            'edit' => Pages\EditAddOn::route('/{record}/edit'),
        ];
    }
}
