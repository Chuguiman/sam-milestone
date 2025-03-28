<?php

namespace App\Filament\Resources\OrganizationResource\RelationManagers;

use App\Models\User;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Spatie\Permission\Models\Role;

class MembersRelationManager extends RelationManager
{
    protected static string $relationship = 'members';

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $title = 'Miembros';

    protected static ?string $icon = 'heroicon-o-users';

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                // Sección de Información de Usuario - Solo visible al crear/adjuntar
                Forms\Components\Section::make('Información de Usuario')
                    ->description('Información básica del miembro.')
                    ->icon('heroicon-o-user-circle')
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
                                    ->placeholder('Introduce el nombre completo'),
                                    
                                Forms\Components\TextInput::make('email')
                                    ->label('Correo electrónico')
                                    ->email()
                                    ->required()
                                    ->maxLength(255)
                                    ->placeholder('usuario@email.com')
                                    ->unique(table: User::class, ignoreRecord: true)
                                    ->disabled(fn ($record) => $record !== null)
                                    ->dehydrated(fn ($state, $record) => $record === null),
                            ])
                            ->columnSpan(1),
                    ])
                    ->visible(fn ($livewire) => $livewire instanceof \Filament\Resources\RelationManagers\RelationManager && $livewire->mountedTableAction === 'attach'),
                
                // Sección de Seguridad
                Forms\Components\Section::make('Seguridad')
                    ->description('Gestión de contraseña y roles del miembro.')
                    ->icon('heroicon-o-lock-closed')
                    ->columns(2)
                    ->schema([
                        // Primera columna - Contraseña (solo visible al crear nuevos usuarios)
                        Forms\Components\Group::make()
                            ->schema([
                                Forms\Components\TextInput::make('password')
                                    ->label('Contraseña')
                                    ->password()
                                    ->dehydrated(fn ($state) => filled($state))
                                    ->required(fn ($record) => !$record)
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
                            ])
                            ->columnSpan(1)
                            ->visible(fn ($livewire) => $livewire instanceof \Filament\Resources\RelationManagers\RelationManager && $livewire->mountedTableAction === 'attach'),
                        
                        // Segunda columna - Roles (siempre visible)
                        Forms\Components\Group::make()
                            ->schema([
                                Forms\Components\Select::make('roles')
                                    ->label('Roles')
                                    ->options(function() {
                                        // Sin filtrar por organización - mostrar todos los roles disponibles
                                        return Role::pluck('name', 'id')->toArray();
                                    })
                                    ->multiple()
                                    ->preload()
                                    ->searchable()
                                    ->helperText('Seleccione los roles que tendrá este usuario'),
                            ])
                            ->columnSpan(fn ($livewire) => $livewire instanceof \Filament\Resources\RelationManagers\RelationManager && $livewire->mountedTableAction === 'attach' ? 1 : 2),
                    ]),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                Tables\Columns\ImageColumn::make('avatar')
                    ->label('Avatar')
                    ->circular()
                    ->defaultImageUrl(fn (User $record): string => 'https://ui-avatars.com/api/?background=0D8ABC&color=fff&name=' . urlencode($record->name)),
                    
                Tables\Columns\TextColumn::make('name')
                    ->label('Nombre')
                    ->searchable()
                    ->sortable(),
                    
                Tables\Columns\TextColumn::make('email')
                    ->label('Correo electrónico')
                    ->searchable()
                    ->sortable(),
                    
                Tables\Columns\TextColumn::make('roles.name')
                    ->label('Roles')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'super-admin' => 'danger',
                        'admin' => 'success',
                        'user' => 'info',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => strtoupper($state)),
                    
                Tables\Columns\TextColumn::make('email_verified_at')
                    ->label('Verificado')
                    ->sortable()
                    ->toggleable()
                    ->getStateUsing(fn (User $record): string => $record->email_verified_at ? 'Sí' : 'No')
                    ->icon(fn (User $record): string => $record->email_verified_at ? 'heroicon-o-check-circle' : 'heroicon-o-x-circle')
                    ->iconColor(fn (User $record): string => $record->email_verified_at ? 'success' : 'danger'),
                    
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Fecha de registro')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('roles')
                    ->label('Rol')
                    ->relationship('roles', 'name')
                    ->multiple()
                    ->preload(),
                    
                Tables\Filters\Filter::make('verified')
                    ->label('Verificados')
                    ->query(fn (Builder $query): Builder => $query->whereNotNull('email_verified_at')),
            ])
            ->headerActions([
                Tables\Actions\AttachAction::make()
                    ->label('Vincular miembro')
                    ->form(fn (Tables\Actions\AttachAction $action): array => [
                        $action->getRecordSelect()
                            ->label('Usuario')
                            ->searchable(['name', 'email'])
                            ->placeholder('Seleccione un usuario existente')
                            ->createOptionForm([
                                // Formulario para crear nuevo usuario
                                Forms\Components\TextInput::make('name')
                                    ->label('Nombre')
                                    ->required()
                                    ->maxLength(255),
                                    
                                Forms\Components\TextInput::make('email')
                                    ->label('Correo electrónico')
                                    ->email()
                                    ->required()
                                    ->unique(User::class)
                                    ->maxLength(255),
                                    
                                Forms\Components\TextInput::make('password')
                                    ->label('Contraseña')
                                    ->password()
                                    ->required()
                                    ->minLength(8)
                                    ->maxLength(255)
                                    ->confirmed(),
                                    
                                Forms\Components\TextInput::make('password_confirmation')
                                    ->label('Confirmar contraseña')
                                    ->password()
                                    ->required(),
                            ]),
                            
                        Forms\Components\Select::make('roles')
                            ->label('Rol')
                            ->options(function() {
                                // Sin filtrar por organización - mostrar todos los roles
                                return Role::pluck('name', 'id')->toArray();
                            })
                            ->multiple()
                            ->preload()
                            ->searchable(),
                    ])
                    ->preloadRecordSelect()
                    ->after(function (array $data, $record) {
                        // Asignar roles al usuario después de adjuntarlo
                        if (isset($data['roles'])) {
                            $user = User::find($record->id);
                            $roles = Role::findMany($data['roles']);
                            
                            foreach ($roles as $role) {
                                $user->assignRole($role);
                            }
                        }
                    }),
            ])
            ->actions([
                Tables\Actions\EditAction::make()
                    ->label('Editar roles')
                    ->modalHeading('Editar roles del miembro')
                    ->form([
                        Forms\Components\Select::make('roles')
                            ->label('Roles')
                            ->options(function() {
                                // Sin filtrar por organización
                                return Role::pluck('name', 'id')->toArray();
                            })
                            ->multiple()
                            ->preload()
                            ->required(),
                    ])
                    ->using(function (array $data, $record) {
                        $user = User::find($record->id);
                        
                        // Actualizar roles
                        if (isset($data['roles'])) {
                            $user->roles()->sync($data['roles']);
                        }
                        
                        return $record;
                    }),
                    
                Tables\Actions\DetachAction::make()
                    ->label('Desvincular')
                    ->modalHeading('¿Desea desvincular miembro de la organización?')
                    ->modalDescription('¿Está seguro de que desea desvincular a este miembro de la organización?'),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DetachBulkAction::make()
                        ->label('Desvincular seleccionados')
                        ->modalHeading('¿Desea desvincular miembros seleccionados?')
                        ->modalDescription('¿Está seguro de que desea desvincular los miembros seleccionados de la organización?'),
                ]),
            ]);
    }
}