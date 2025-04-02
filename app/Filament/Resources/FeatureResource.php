<?php

namespace App\Filament\Resources;

use App\Filament\Resources\FeatureResource\Pages;
use App\Filament\Resources\FeatureResource\RelationManagers;
use App\Models\Feature;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class FeatureResource extends Resource
{
    protected static ?string $model = Feature::class;

    protected static ?string $navigationIcon = 'heroicon-o-puzzle-piece';

    protected static ?string $navigationGroup = 'Suscripciones';
    
    protected static ?int $navigationSort = 20;

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
                            ->helperText('Identificador único para esta característica (sin espacios)'),
                    ]),
                    
                    Forms\Components\Textarea::make('description')
                        ->label('Descripción')
                        ->rows(3)
                        ->maxLength(500)
                        ->helperText('Descripción detallada de esta característica para mostrar a los usuarios'),
                ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                ->label('Nombre')
                ->searchable()
                ->sortable(),
                
            Tables\Columns\TextColumn::make('code')
                ->label('Código')
                ->searchable()
                ->sortable(),
                
            Tables\Columns\TextColumn::make('description')
                ->label('Descripción')
                ->limit(50)
                ->tooltip(fn ($record): string => $record->description ?? ''),
                
            Tables\Columns\TextColumn::make('plans.name')
                ->label('Planes')
                ->badge()
                ->color(fn (string $state): string => match ($state) {
                    'Essencial' => 'gray',
                    'Business' => 'warning',
                    'Enterprise' => 'success',
                }),
                
            Tables\Columns\TextColumn::make('created_at')
                ->label('Creado')
                ->dateTime('d/m/Y')
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
            //RelationManagers\PlansRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListFeatures::route('/'),
            'create' => Pages\CreateFeature::route('/create'),
            'edit' => Pages\EditFeature::route('/{record}/edit'),
        ];
    }
}
