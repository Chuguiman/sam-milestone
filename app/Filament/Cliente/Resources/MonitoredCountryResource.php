<?php

namespace App\Filament\Cliente\Resources;

use App\Filament\Cliente\Resources\MonitoredCountryResource\Pages;
use App\Filament\Cliente\Resources\MonitoredCountryResource\RelationManagers;
use App\Models\Country;
use App\Models\Organization;
use App\Models\MonitoredCountry;
use App\Services\SubscriptionManagerService;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\Auth;

class MonitoredCountryResource extends Resource
{
    protected static ?string $model = MonitoredCountry::class;

    protected static ?string $navigationIcon = 'heroicon-o-globe-americas';

    protected static ?string $navigationGroup = 'Settings';

    protected static ?string $navigationLabel = 'Países monitoreados';

    protected static ?string $modelLabel = 'País monitoreado';

    protected static ?string $pluralModelLabel = 'Países monitoreados';

    /**
     * Obtiene la organización actual
     */
    protected static function getOrganization()
    {
        return Filament::getTenant();
    }


    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('country_id')
                    ->label('País')
                    ->options(Country::orderBy('name')->pluck('name', 'id'))
                    ->searchable()
                    ->required(),
                
                Forms\Components\Toggle::make('is_active')
                    ->label('Activo')
                    ->default(true),
                
                Forms\Components\KeyValue::make('settings')
                    ->label('Configuración adicional')
                    ->keyLabel('Propiedad')
                    ->valueLabel('Valor')
                    ->addActionLabel('Añadir configuración')
                    ->columnSpan('full'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('country.emoji')
                    ->label('Bandera')
                    ->searchable(false)
                    ->sortable(false),
                
                Tables\Columns\TextColumn::make('country.name')
                    ->label('País')
                    ->sortable()
                    ->searchable(),
                
                Tables\Columns\TextColumn::make('country.iso2')
                    ->label('Código')
                    ->sortable()
                    ->searchable(),
                
                Tables\Columns\ToggleColumn::make('is_active')
                    ->label('Activo')
                    ->sortable(),
                
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Fecha de alta')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('is_active')
                    ->label('Estado')
                    ->options([
                        '1' => 'Activo',
                        '0' => 'Inactivo',
                    ]),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make()
                    ->requiresConfirmation(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\BulkAction::make('activate')
                        ->label('Activar')
                        ->icon('heroicon-o-check')
                        ->action(fn (Builder $query) => $query->update(['is_active' => true])),
                    
                    Tables\Actions\BulkAction::make('deactivate')
                        ->label('Desactivar')
                        ->icon('heroicon-o-x-mark')
                        ->color('danger')
                        ->action(fn (Builder $query) => $query->update(['is_active' => false])),
                    
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateHeading('No hay países monitoreados')
            ->emptyStateDescription('Añade países para comenzar a monitorearlos.')
            ->emptyStateActions([
                Tables\Actions\Action::make('create')
                    ->label('Añadir país')
                    ->url(fn (): string => self::getUrl('create'))
                    ->icon('heroicon-o-plus')
                    ->button(),
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
            'index' => Pages\ListMonitoredCountries::route('/'),
            'create' => Pages\CreateMonitoredCountry::route('/create'),
            'edit' => Pages\EditMonitoredCountry::route('/{record}/edit'),
        ];
    }

    /**
     * Define la consulta elocuente para mostrar sólo los países de la organización actual
     */
    public static function getEloquentQuery(): Builder
    {
        $organization = self::getOrganization();
        
        return parent::getEloquentQuery()
            ->where('organization_id', $organization->id);
    }
    
    /**
     * Define la insignia en el menú de navegación
     */
    public static function getNavigationBadge(): ?string
    {
        $organization = self::getOrganization();
        if (!$organization) return null;
        
        $count = $organization->activeMonitoredCountries()->count();
        $limit = $organization->getMonitoredCountriesLimit();
        
        if ($limit <= 0) {
            return (string) $count;
        }
        
        return "{$count}/{$limit}";
    }
    
    /**
     * Define el color de la insignia en el menú de navegación
     */
    public static function getNavigationBadgeColor(): string|array|null
    {
        $organization = self::getOrganization();
        if (!$organization) return null;
        
        $count = $organization->activeMonitoredCountries()->count();
        $limit = $organization->getMonitoredCountriesLimit();
        
        if ($limit <= 0) {
            return 'success';
        }
        
        $percentage = $limit > 0 ? ($count / $limit) * 100 : 0;
        
        if ($percentage >= 90) {
            return 'danger';
        }
        
        if ($percentage >= 70) {
            return 'warning';
        }
        
        return 'success';
    }
    
    /**
     * Determina si el usuario puede crear nuevos registros
     */
    public static function canCreate(): bool
    {
        $organization = self::getOrganization();
        if (!$organization) return false;
        
        return !$organization->hasReachedMonitoredCountriesLimit();
    }
}
