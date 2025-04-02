<?php

namespace App\Filament\Resources;

use App\Filament\Resources\DiscountResource\Pages;
use App\Filament\Resources\DiscountResource\RelationManagers;
use App\Models\Discount;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class DiscountResource extends Resource
{
    protected static ?string $model = Discount::class;

    protected static ?string $navigationIcon = 'heroicon-o-tag';

    protected static ?string $navigationGroup = 'Suscripciones';
    
    protected static ?int $navigationSort = 40;
    
    protected static ?string $recordTitleAttribute = 'code';
    
    protected static ?string $modelLabel = 'Descuento';
    
    protected static ?string $pluralModelLabel = 'Descuentos';

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
                                ->helperText('Código único para aplicar el descuento'),
                        ]),
                        
                        Forms\Components\Grid::make([
                            'default' => 1,
                            'sm' => 3,
                        ])
                        ->schema([
                            Forms\Components\Select::make('type')
                                ->label('Tipo')
                                ->options([
                                    'percentage' => 'Porcentaje',
                                    'fixed' => 'Monto fijo',
                                ])
                                ->required()
                                ->default('percentage')
                                ->reactive(),
                                
                            Forms\Components\TextInput::make('value')
                                ->label(fn (callable $get) => $get('type') === 'percentage' ? 'Porcentaje (%)' : 'Monto')
                                ->required()
                                ->numeric()
                                ->minValue(0)
                                ->maxValue(fn (callable $get) => $get('type') === 'percentage' ? 100 : null)
                                ->step(0.01)
                                ->suffix(fn (callable $get) => $get('type') === 'percentage' ? '%' : ''),
                                
                            Forms\Components\Toggle::make('is_active')
                                ->label('Activo')
                                ->default(true)
                                ->onIcon('heroicon-s-check')
                                ->offIcon('heroicon-s-x-mark'),
                        ]),
                        
                        Forms\Components\Grid::make([
                            'default' => 1,
                            'sm' => 2,
                        ])
                        ->schema([
                            Forms\Components\TextInput::make('max_uses')
                                ->label('Usos máximos')
                                ->helperText('Número máximo de veces que se puede usar este código. Dejar en blanco para ilimitado.')
                                ->nullable()
                                ->numeric()
                                ->minValue(1),
                                
                            Forms\Components\DateTimePicker::make('expires_at')
                                ->label('Fecha de expiración')
                                ->helperText('Dejar en blanco para que no expire nunca.')
                                ->nullable(),
                        ]),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('code')
                    ->label('Código')
                    ->searchable()
                    ->sortable()
                    ->copyable(),
                    
                Tables\Columns\TextColumn::make('name')
                    ->label('Nombre')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('type')
                    ->label('Tipo')
                    ->badge()
                    ->color(fn (bool $state): string => $state ? 'success' : 'gray')
                    ->formatStateUsing(fn (bool $state): string => $state ? 'percentage' : 'fixed'),
                    
                Tables\Columns\TextColumn::make('value')
                    ->label('Valor')
                    ->formatStateUsing(function ($state, $record) {
                        if ($record->type === 'percentage') {
                            return $state . '%';
                        }
                        return '$' . number_format($state, 2);
                    })
                    ->sortable(),
                    
                Tables\Columns\TextColumn::make('used')
                    ->label('Usado')
                    ->formatStateUsing(function ($state, $record) {
                        if ($record->max_uses) {
                            return $state . ' / ' . $record->max_uses;
                        }
                        return $state . ' / ∞';
                    })
                    ->sortable(),
                    
                Tables\Columns\IconColumn::make('is_active')
                    ->label('Activo')
                    ->boolean()
                    ->sortable(),
                    
                Tables\Columns\TextColumn::make('expires_at')
                    ->label('Expira')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
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
            'index' => Pages\ListDiscounts::route('/'),
            'create' => Pages\CreateDiscount::route('/create'),
            'edit' => Pages\EditDiscount::route('/{record}/edit'),
        ];
    }
}
