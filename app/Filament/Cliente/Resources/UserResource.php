<?php

namespace App\Filament\Cliente\Resources;

use App\Filament\Cliente\Resources\UserResource\Pages;
use App\Filament\Cliente\Resources\UserResource\RelationManagers;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Support\Enums\Alignment;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\Auth;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $navigationIcon = 'heroicon-o-users';

    protected static ?string $navigationGroup = 'Settings';

    protected static ?string $tenantRelationshipName = 'members';
    
    protected static ?string $tenantOwnershipRelationshipName = 'organizations';


    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Información de Perfil')
                    ->description('Actualiza la información de tu cuenta y la imagen de perfil.')
                    ->icon('heroicon-o-user-circle')
                    ->aside()
                    ->extraAttributes(['class' => 'flex justify-start gap-2'])
                    ->columns(2)
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
                                    ->unique(ignoreRecord: true),
                            ])
                            ->columnSpan(1),
                    ]),
                    
                Forms\Components\Section::make('Seguridad')
                    ->description('Asegúrate de usar una contraseña segura para proteger tu cuenta.')
                    ->icon('heroicon-o-lock-closed')
                    ->aside()
                    ->schema([
                        Forms\Components\TextInput::make('password')
                            ->label('Contraseña')
                            ->password()
                            ->dehydrated(fn ($state) => filled($state))
                            ->required(fn ($record) => ! $record)
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
                            ->placeholder('••••••••'),

                        Forms\Components\Select::make('roles')
                            ->relationship('roles', 'name')
                            ->multiple()
                            ->preload()
                            ->live()
                            ->disableOptionWhen(function ($value, $label) {
                                // Obtener el usuario actual
                                $user = Auth::user();
                                
                                // Verificar si el usuario tiene el rol "user"
                                $isUser = $user->roles()->where('name', 'User')->exists();
                                
                                // Si es un usuario regular, deshabilitar todas las opciones
                                if ($isUser) {
                                    return true; // Deshabilita todas las opciones para usuarios regulares
                                }
                                
                                // Para super-admin, solo deshabilitar super-admin
                                if ($user->roles()->where('name', 'super-admin')
                                        ->orWhere('name', 'super_admin')->exists()) {
                                    if ($label == 'super-admin' || $label == 'super_admin') {
                                        return true;
                                    }
                                    return false; // Permite todos los demás roles
                                }
                                
                                // Para admin, deshabilitar super-admin y admin
                                if ($user->roles()->where('name', 'admin')
                                        ->orWhere('name', 'Admin')->exists()) {
                                    if ($label == 'super-admin' || $label == 'super_admin' || 
                                        $label == 'admin' || $label == 'Admin') {
                                        return true;
                                    }
                                    return false; // Permite roles menores como "user" y "Registered"
                                }
                                
                                // Por defecto, deshabilitar todo para otros roles no especificados
                                return true;
                            })
                            ->saveRelationshipsUsing(function (Model $record, $state) {
                                $organizationId = getPermissionsTeamId();
                        
                                if (!empty(config('permission.column_names.organization_foreign_key')) && !empty($organizationId)) {
                                    $record->roles()->syncWithPivotValues($state, [
                                        config('permission.column_names.organization_foreign_key') => $organizationId
                                    ]);
                                } else {
                                    $record->roles()->sync($state);
                                }
                            })
                            
                        
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

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user = Auth::user();
        
        // Verificar rol
        $isSuperAdmin = $user->roles()->where('name', 'super_admin')->exists();
        $isAdmin = $user->roles()->where('name', 'Admin')->exists();
        
        // Si es usuario regular, solo ver su propio perfil
        if (!$isSuperAdmin && !$isAdmin) {
            $query->where('id', $user->id);
        }
        
        // En todos los casos, filtrar solo por usuarios de la organización actual
        $tenant = Filament::getTenant();
        if ($tenant) {
            $query->whereHas('organizations', function ($q) use ($tenant) {
                $q->where('organizations.id', $tenant->id);
            });
        }
        
        return $query;
    }

    public static function getNavigationBadge(): ?string
    {
        // Obtener solo el conteo de usuarios de la organización actual
        $tenant = Filament::getTenant();
        
        if (!$tenant) {
            return null;
        }
        
        // Obtener el conteo de usuarios en la organización actual
        return $tenant->members()->count();
    }
}
