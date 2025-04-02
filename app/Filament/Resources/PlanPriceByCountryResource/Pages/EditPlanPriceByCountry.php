<?php

namespace App\Filament\Resources\PlanPriceByCountryResource\Pages;

use App\Filament\Resources\PlanPriceByCountryResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditPlanPriceByCountry extends EditRecord
{
    protected static string $resource = PlanPriceByCountryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
