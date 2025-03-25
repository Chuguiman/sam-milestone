<?php

namespace App\Filament\Pages\Tenancy;

use App\Models\Organization;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Pages\Tenancy\EditTenantProfile;
use Illuminate\Support\Str;

class EditOrganizationProfile extends EditTenantProfile
{
    public static function getLabel(): string
    {
        return 'Perfil de la Organización';
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('Información Básica')
                    ->schema([
                        TextInput::make('name')
                            ->label('Nombre')
                            ->required()
                            ->maxLength(255)
                            ->live(onBlur: true)
                            ->afterStateUpdated(function (string $state, callable $set) {
                                $set('slug', Str::slug($state));
                            }),
                            
                        TextInput::make('slug')
                            ->label('URL')
                            ->required()
                            ->unique(Organization::class, 'slug', ignoreRecord: true)
                            ->maxLength(255),
                            
                        FileUpload::make('avatar')
                            ->label('Logo')
                            ->image()
                            ->directory('organizations')
                            ->maxSize(1024),
                            
                        TextInput::make('support_email')
                            ->label('Email de Soporte')
                            ->email()
                            ->maxLength(255),
                    ]),
                    
                Section::make('Dirección')
                    ->schema([
                        TextInput::make('address')
                            ->label('Dirección')
                            ->maxLength(255),
                            
                        TextInput::make('city')
                            ->label('Ciudad')
                            ->maxLength(255),
                            
                        TextInput::make('country')
                            ->label('País')
                            ->maxLength(255),
                            
                        TextInput::make('postcode')
                            ->label('Código Postal')
                            ->maxLength(255),
                    ]),
                    
                Section::make('Información Fiscal')
                    ->schema([
                        TextInput::make('taxId')
                            ->label('Identificación Fiscal')
                            ->maxLength(255),
                            
                        TextInput::make('vat_country')
                            ->label('País de IVA')
                            ->maxLength(255),
                            
                        Toggle::make('taxable')
                            ->label('¿Sujeto a impuestos?')
                            ->required(),
                    ]),
            ]);
    }
}