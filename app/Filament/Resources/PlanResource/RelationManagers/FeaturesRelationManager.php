<?php

namespace App\Filament\Resources\PlanResource\RelationManagers;

use App\Models\Feature;
use Filament\Forms;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Actions\AttachAction;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class FeaturesRelationManager extends RelationManager
{
    protected static string $relationship = 'features';

    protected static ?string $recordTitleAttribute = 'name';

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('feature_id')
                    ->label('Característica')
                    //->relationship('features', 'name')
                    ->options(Feature::all()->pluck('name', 'id'))
                    ->searchable()
                    ->required()
/*                     ->disabledOn('edit')
                    ->hiddenOn('edit') */,
                    
                Forms\Components\TextInput::make('value')
                    ->label('Valor')
                    ->numeric()
                    ->helperText('Límite numérico o valor para esta característica (ej: 10 usuarios, 5GB de almacenamiento)')
                    ->nullable(),
                    
                Forms\Components\Textarea::make('notes')
                    ->label('Notas')
                    ->helperText('Notas adicionales sobre esta característica en este plan')
                    ->rows(2)
                    ->nullable()
                    ->maxLength(255),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Nombre')
                    ->searchable()
                    ->sortable(),
                
                Tables\Columns\TextColumn::make('code')
                    ->label('Código')
                    ->searchable(),
                    
                Tables\Columns\TextColumn::make('pivot.value')
                    ->label('Valor/Límite')
                    ->tooltip(fn ($record) => $record->description ?? '')
                    ->formatStateUsing(function ($state, $record) {
                        if (is_null($state)) {
                            return '∞'; // Símbolo de infinito para valores ilimitados
                        }
                        
                        if (is_numeric($state)) {
                            return number_format($state, 0);
                        }
                        
                        return $state;
                    }),
                    
                Tables\Columns\IconColumn::make('is_enabled')
                    ->label('Habilitada')
                    ->boolean()
                    ->getStateUsing(fn ($record) => !is_null($record->pivot->value) || $record->pivot->value === 0),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make(),
                Tables\Actions\AttachAction::make()
                    ->label('Añadir característica')
                    ->preloadRecordSelect()
                    ->form(fn (AttachAction $action): array => [
                        $action->getRecordSelect()->label('Característica'),
                        TextInput::make('value')
                            ->label('Valor/Límite')
                            ->numeric()
                            ->nullable()
                            ->helperText('Deja en blanco para ilimitado'),
                    ]),
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
    
}
