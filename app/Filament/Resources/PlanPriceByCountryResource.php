<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PlanPriceByCountryResource\Pages;
use App\Filament\Resources\PlanPriceByCountryResource\RelationManagers;
use App\Models\PlanPriceByCountry;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class PlanPriceByCountryResource extends Resource
{
    protected static ?string $model = PlanPriceByCountry::class;

    protected static ?string $navigationIcon = 'heroicon-o-currency-dollar';

    protected static ?string $navigationGroup = 'Suscripciones';
    
    protected static ?int $navigationSort = 90;
    
    protected static ?string $modelLabel = 'Precios x País';
    

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('plan_id')
                    ->relationship('plan', 'name')
                    ->required(),
                Forms\Components\TextInput::make('country_code')
                    ->required()
                    ->maxLength(3),
                Forms\Components\TextInput::make('price')
                    ->required()
                    ->numeric()
                    ->prefix('$'),
                Forms\Components\TextInput::make('currency')
                    ->required()
                    ->maxLength(3),
                Forms\Components\TextInput::make('stripe_price_id')
                    ->maxLength(255),
                Forms\Components\TextInput::make('billing_interval')
                    ->required()
                    ->maxLength(255)
                    ->default('monthly'),
                Forms\Components\TextInput::make('original_price')
                    ->numeric(),
                Forms\Components\TextInput::make('discount_percentage')
                    ->required()
                    ->numeric()
                    ->default(0),
                Forms\Components\TextInput::make('metadata'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('plan.name')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('country_code')
                    ->searchable(),
                Tables\Columns\TextColumn::make('price')
                    ->money()
                    ->sortable(),
                Tables\Columns\TextColumn::make('currency')
                    ->searchable(),
                Tables\Columns\TextColumn::make('stripe_price_id')
                    ->searchable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('billing_interval')
                    ->searchable(),
                Tables\Columns\TextColumn::make('original_price')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('discount_percentage')
                    ->numeric()
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('plan_id')
                    ->label('Plan')
                    ->relationship('plan', 'name')
                    ->searchable()
                    ->preload(),
            
                Tables\Filters\SelectFilter::make('country_code')
                    ->label('País')
                    ->options(
                        PlanPriceByCountry::query()
                            ->select('country_code')
                            ->distinct()
                            ->pluck('country_code', 'country_code')
                            ->toArray()
                    )
                    ->searchable(),
            
                Tables\Filters\SelectFilter::make('billing_interval')
                    ->label('Ciclo de Facturación')
                    ->options([
                        'monthly' => 'Mensual',
                        'monthly_annual' => 'Anual (mensual)',
                        'annual' => 'Anual (único)',
                    ]),
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
            'index' => Pages\ListPlanPriceByCountries::route('/'),
            'create' => Pages\CreatePlanPriceByCountry::route('/create'),
            'edit' => Pages\EditPlanPriceByCountry::route('/{record}/edit'),
        ];
    }
}
