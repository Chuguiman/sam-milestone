<?php

namespace App\Filament\Resources\PlanPriceByCountryResource\Pages;

use App\Filament\Resources\PlanPriceByCountryResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListPlanPriceByCountries extends ListRecords
{
    protected static string $resource = PlanPriceByCountryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
