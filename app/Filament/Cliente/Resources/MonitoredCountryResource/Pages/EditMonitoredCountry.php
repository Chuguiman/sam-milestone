<?php

namespace App\Filament\Cliente\Resources\MonitoredCountryResource\Pages;

use App\Filament\Cliente\Resources\MonitoredCountryResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditMonitoredCountry extends EditRecord
{
    protected static string $resource = MonitoredCountryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
