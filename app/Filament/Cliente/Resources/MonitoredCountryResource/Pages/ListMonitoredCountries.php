<?php

namespace App\Filament\Cliente\Resources\MonitoredCountryResource\Pages;

use App\Filament\Cliente\Resources\MonitoredCountryResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;


use Filament\Facades\Filament;

use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;

class ListMonitoredCountries extends ListRecords
{
    protected static string $resource = MonitoredCountryResource::class;

/*     protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    } */

     /**
     * Obtiene la organización actual
     */
    protected function getOrganization()
    {
        return Filament::getTenant();
    }

    /**
     * Define las acciones del encabezado
     */
    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->visible(function () {
                    $organization = $this->getOrganization();
                    if (!$organization) return false;
                    
                    return !$organization->hasReachedMonitoredCountriesLimit();
                }),
        ];
    }
    
    /**
     * Define el subtítulo de la página
     */
    public function getSubheading(): string|Htmlable|null
    {
        $organization = $this->getOrganization();
        if (!$organization) return null;
        
        $limit = $organization->getMonitoredCountriesLimit();
        $count = $organization->activeMonitoredCountries()->count();
        
        if ($limit <= 0) {
            return new HtmlString("Actualmente monitoreando <strong>{$count}</strong> países. <span class='text-success-500'>Sin límite en tu plan.</span>");
        }
        
        $remaining = $limit - $count;
        $status = 'text-success-500';
        
        if ($remaining <= 0) {
            $status = 'text-danger-500';
            return new HtmlString("Has alcanzado el <strong>límite de países</strong> de tu plan ({$limit}). Contacta con soporte para aumentar este límite.");
        }
        
        if ($remaining <= 2) {
            $status = 'text-warning-500';
        }
        
        return new HtmlString("Actualmente monitoreando <strong>{$count}</strong> países. <span class='{$status}'>Tienes {$remaining} países disponibles</span> de {$limit} según tu plan.");
    }
    
    /**
     * Define la descripción de la tabla
     */
    protected function getTableDescription(): ?string
    {
        $organization = $this->getOrganization();
        $limit = $organization->getMonitoredCountriesLimit();
        
        if ($limit > 0) {
            return "Límite según tu plan: {$limit} países";
        }
        
        return null;
    }
}
