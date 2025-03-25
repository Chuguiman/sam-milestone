<?php

namespace App\Filament\Pages\Tenancy;

use App\Models\Organization;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Pages\Tenancy\RegisterTenant;
use Illuminate\Support\Str;

class RegisterOrganization extends RegisterTenant
{
    public static function getLabel(): string
    {
        return 'Registrar Organización';
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                TextInput::make('name')
                    ->label('Nombre de la Organización')
                    ->required()
                    ->maxLength(255)
                    ->live(onBlur: true)
                    ->afterStateUpdated(function (string $state, callable $set) {
                        $set('slug', Str::slug($state));
                    }),
                    
                TextInput::make('slug')
                    ->label('URL de la Organización')
                    ->required()
                    ->unique(Organization::class, 'slug')
                    ->maxLength(255),
                    
                TextInput::make('support_email')
                    ->label('Email de Soporte')
                    ->email()
                    ->maxLength(255),
                    
                Toggle::make('taxable')
                    ->label('¿Sujeto a impuestos?')
                    ->default(true),
            ]);
    }

    protected function handleRegistration(array $data): Organization
    {
        // Crear la organización
        $organization = Organization::create(array_merge($data, [
            'status' => 'active',
        ]));

        // Asociar el usuario actual con la organización
        $organization->members()->attach(auth()->user());

        return $organization;
    }
}