<?php

namespace App\Filament\Resources;

use App\Filament\Resources\OrganizationResource\Pages;
use App\Filament\Resources\OrganizationResource\RelationManagers;
use App\Models\City;
use App\Models\Country;
use App\Models\Organization;
use App\Models\State;
use Filament\Forms;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class OrganizationResource extends Resource
{
    protected static ?string $model = Organization::class;

    protected static ?string $navigationIcon = 'heroicon-o-building-office-2';

    protected static ?string $navigationGroup = 'User Management';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Información básica')
                    ->description('Información principal de la organización')
                    ->icon('heroicon-o-building-office')
                    ->columns(3)
                    ->schema([
                        Forms\Components\FileUpload::make('avatar')
                            ->label('Avatar')
                            ->avatar()
                            ->disk('public')
                            ->directory('organizations/avatars')
                            ->image()
                            ->maxSize(2048)
                            ->visibility('public')
                            ->helperText('Imagen de la organización (max. 2MB)')
                            ->columnSpan(1),
                            
                        Forms\Components\Group::make()
                            ->schema([
                                Forms\Components\TextInput::make('name')
                                    ->label('Nombre')
                                    ->required()
                                    ->maxLength(255),
                                    
                                Forms\Components\TextInput::make('support_email')
                                    ->label('Email de soporte')
                                    ->email()
                                    ->maxLength(255),
                            ])
                            ->columnSpan(1),
                            
                        Forms\Components\Group::make()
                            ->schema([
                                Forms\Components\Select::make('status')
                                    ->label('Estado')
                                    ->options([
                                        'active' => 'Activo',
                                        'inactive' => 'Inactivo',
                                        'pending' => 'Pendiente',
                                    ])
                                    ->default('active')
                                    ->required()
                                    ->selectablePlaceholder(false),
                                    
                                Forms\Components\TextInput::make('slug')
                                    ->label('URL de la organización')
                                    ->required()
                                    ->unique(Organization::class, 'slug', ignoreRecord: true)
                                    ->maxLength(255)
                                    ->helperText('Esta será la URL para acceder a la organización'),
                            ])
                            ->columnSpan(1),
                    ]),
                    
                Forms\Components\Section::make('Dirección fiscal')
                    ->description('Datos de facturación y localización')
                    ->icon('heroicon-o-map-pin')
                    ->columns(2)
                    ->collapsed()
                    ->schema([
                        Forms\Components\TextInput::make('address')
                            ->label('Dirección')
                            ->maxLength(255),
                        Forms\Components\Select::make('country_id')
                            ->relationship(name : 'country', titleAttribute:'name')
                            ->label('País')
                            ->searchable()
                            ->preload()
                            ->live()
                            ->afterStateUpdated(function (Set $set) {
                                $set('state_id',null);
                                $set('city_id',null);
                            } )
                            ->required(),
                        Forms\Components\Select::make('state_id')
                            ->label('Estado/Provincia')
                            ->options(function (Get $get): array {
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
                            ->live()
                            ->afterStateUpdated(fn (Set $set) => $set('city_id', null))
                            ->required()
                            ->visible(fn (Get $get): bool => (bool) $get('country_id')),
                        
                            Forms\Components\Select::make('city_id')
                            ->label('Ciudad')
                            ->options(function (Get $get): array {
                                $stateId = $get('state_id');
                                $countryId = $get('country_id');
                                
                                // Caso especial para Reino Unido (o cualquier otro país que tenga problemas similares)
                                $specialCountries = [
                                    // Ajusta estos IDs según tus datos
                                    'United Kingdom' => true,  // Por nombre
                                    826 => true,               // Por ID ISO (ejemplo)
                                    // Añade otros países con problemas similares si es necesario
                                ];
                                
                                // Si es un país especial y hay un estado seleccionado
                                $isSpecialCountry = isset($specialCountries[$countryId]) || 
                                                  (is_string($countryId) && isset($specialCountries[$countryId]));
                                
                                if ($isSpecialCountry && $stateId) {
                                    // Para países especiales, consulta más amplia para encontrar ciudades
                                    return City::query()
                                        ->where(function ($query) use ($stateId) {
                                            // Buscar ciudades con este state_id
                                            $query->where('state_id', $stateId)
                                                // O ciudades donde el state_id podría estar en null pero relacionadas con el país
                                                ->orWhereNull('state_id');
                                        })
                                        ->orderBy('name')
                                        ->pluck('name', 'id')
                                        ->toArray();
                                }
                                
                                // Comportamiento estándar para el resto de países
                                if (!$stateId) {
                                    return [];
                                }
                                
                                // Verificar si la consulta devuelve resultados
                                $cities = City::query()
                                    ->where('state_id', $stateId)
                                    ->orderBy('name')
                                    ->get();
                                    
                                // Si no hay ciudades para este estado, intentar un enfoque alternativo
                                if ($cities->isEmpty() && $countryId) {
                                    // Buscar ciudades por país en lugar de por estado
                                    return City::query()
                                        ->whereHas('state', function ($query) use ($countryId) {
                                            $query->where('country_id', $countryId);
                                        })
                                        ->orderBy('name')
                                        ->pluck('name', 'id')
                                        ->toArray();
                                }
                                
                                return $cities->pluck('name', 'id')->toArray();
                            })
                            ->searchable()
                            ->preload()
                            ->required()
                            ->visible(fn (Get $get): bool => (bool) $get('state_id')),
                            
                        Forms\Components\TextInput::make('postcode')
                            ->label('Código postal')
                            ->maxLength(20),
                    ]),
                    
                Forms\Components\Section::make('Información tributaria')
                    ->description('Datos fiscales para facturación')
                    ->icon('heroicon-o-currency-dollar')
                    ->columns(2)
                    ->collapsed()
                    ->schema([
                        Forms\Components\TextInput::make('tax_id')
                            ->label('Numero de identificacion Fiscal - NIT/CIF/NIF/')
                            ->maxLength(30),
                        
                        Forms\Components\Select::make('vat_country_id')
                            ->relationship(name : 'country', titleAttribute:'name')
                            ->label('País sujeto de Impuestos')
                            ->searchable()
                            ->preload()
                            ->live()
/*                             ->afterStateUpdated(function (Set $set) {
                                $set('state_id',null);
                                $set('city_id',null);
                            } ) */
                            ->required(),
                            
                        Forms\Components\Toggle::make('taxable')
                            ->label('Sujeto a impuestos')
                            ->default(false)
                            ->inline(false)
                            ->onIcon('heroicon-s-check')
                            ->offIcon('heroicon-s-x-mark'),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable(),
                Tables\Columns\TextColumn::make('slug')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\ImageColumn::make('avatar')
                    ->circular(),
                Tables\Columns\TextColumn::make('support_email')
                    ->searchable(),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => strtoupper($state))
                    ->color(fn (string $state): string => match ($state) {
                        'active' => 'success',
                        'inactive' => 'gray',
                        'pending' => 'warning',
                        default => 'gray',
                    }),
                Tables\Columns\ImageColumn::make('members.avatar')
                    ->label('Team Members')
                    ->circular()
                    ->stacked(),
                Tables\Columns\TextColumn::make('address')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('city.name')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('country.emoji')
                    ->label(''),
                Tables\Columns\TextColumn::make('country.name')
                    ->searchable(),
                Tables\Columns\TextColumn::make('postcode')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
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
            RelationManagers\MembersRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListOrganizations::route('/'),
            'create' => Pages\CreateOrganization::route('/create'),
            'edit' => Pages\EditOrganization::route('/{record}/edit'),
        ];
    }
}
