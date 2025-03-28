<?php

namespace App\Filament\Resources;

use App\Filament\Resources\UserResource\Pages;
use App\Filament\Resources\UserResource\RelationManagers;
use App\Models\User;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $navigationIcon = 'heroicon-o-users';

    protected static ?string $navigationGroup = 'User Management';

    public static function form(Form $form): Form
{
    return $form
        ->schema([
            Forms\Components\Section::make('Información de Perfil')
                ->description('Actualiza la información de tu cuenta y la imagen de perfil.')
                ->icon('heroicon-o-user-circle')
                ->columns(2)
                ->aside()
                ->schema([
                    Forms\Components\FileUpload::make('avatar')
                        ->label('Avatar')
                        ->avatar()
                        ->disk('public')
                        ->directory('users/avatars')
                        ->image()
                        ->maxSize(2048)
                        ->visibility('public')
                        ->helperText('Imagen de perfil (máx. 2MB)')
                        ->columnSpan(1),
                        
                    Forms\Components\Group::make()
                        ->schema([
                            Forms\Components\TextInput::make('name')
                                ->label('Nombre')
                                ->required()
                                ->maxLength(255)
                                ->placeholder('Introduce tu nombre completo'),
                                
                            Forms\Components\TextInput::make('email')
                                ->label('Correo electrónico')
                                ->email()
                                ->required()
                                ->maxLength(255)
                                ->placeholder('tu@email.com')
                                ->unique(ignoreRecord: true)
                                ->disabled(fn ($record) => $record !== null) // Deshabilitar si es edición
                                ->dehydrated(fn ($state, $record) => $record === null), // Solo procesar si es creación
                        ])
                        ->columnSpan(1),
                ]),
                
            Forms\Components\Section::make('Seguridad')
                ->description('Asegúrate de usar una contraseña segura para proteger tu cuenta.')
                ->aside()
                ->icon('heroicon-o-lock-closed')
                ->columns(2) // Dividir en 2 columnas
                ->schema([
                    // Primera columna - Contraseñas
                    Forms\Components\Group::make()
                        ->schema([
                            Forms\Components\Toggle::make('change_password')
                                ->label('Cambiar contraseña')
                                ->default(fn ($record) => $record === null) // Activo solo para nuevos usuarios
                                ->visible(fn ($record) => $record !== null) // Solo visible en edición
                                ->live(),
                                
                            Forms\Components\TextInput::make('password')
                                ->label('Contraseña')
                                ->password()
                                ->dehydrated(fn ($state) => filled($state))
                                ->required(fn ($record, $get) => $record === null || $get('change_password'))
                                ->disabled(fn ($record, $get) => $record !== null && !$get('change_password'))
                                ->maxLength(255)
                                ->revealable()
                                ->minLength(8)
                                ->placeholder('••••••••')
                                ->rule('min:8')
                                ->helperText('Mínimo 8 caracteres'),
                                
                            Forms\Components\TextInput::make('password_confirmation')
                                ->label('Confirmar contraseña')
                                ->password()
                                ->dehydrated(false)
                                ->requiredWith('password')
                                ->same('password')
                                ->disabled(fn ($record, $get) => $record !== null && !$get('change_password'))
                                ->placeholder('••••••••'),
                        ])
                        ->columnSpan(1),
                    
                    // Segunda columna - Roles
                    Forms\Components\Group::make()
                        ->schema([
                            Forms\Components\Select::make('roles')
                                ->relationship('roles', 'name')
                                ->saveRelationshipsUsing(function (Model $record, $state) {
                                    // Make sure getPermissionsTeamId() returns a valid organization ID
                                    //$organizationId = getPermissionsTeamId();
                                    
                                    // Add this check to avoid empty column names
                                    if (!empty(config('permission.column_names.team_foreign_key')) && !empty($organizationId)) {
                                        $record->roles()->syncWithPivotValues($state, [
                                            config('permission.column_names.team_foreign_key') => $organizationId
                                        ]);
                                    } else {
                                        // Fall back to simple sync if the team/organization configuration is missing
                                        $record->roles()->sync($state);
                                    }
                                })
                                ->multiple()
                                ->preload()
                        ])
                        ->columnSpan(1),
                ]),
        ]);
}

    
    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\ImageColumn::make('avatar')
                    ->circular()
                    ->label('Photo'),
                Tables\Columns\TextColumn::make('name')
                    ->searchable(),
                Tables\Columns\TextColumn::make('email')
                    ->searchable(),
                Tables\Columns\TextColumn::make('roles.name')
                    ->badge(),
                Tables\Columns\ImageColumn::make('organizations.avatar')
                    ->label('Logo')
                    ->circular(),
                Tables\Columns\TextColumn::make('organizations.name')
                    ->searchable(),
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
            'index' => Pages\ListUsers::route('/'),
            'create' => Pages\CreateUser::route('/create'),
            'edit' => Pages\EditUser::route('/{record}/edit'),
        ];
    }
}
