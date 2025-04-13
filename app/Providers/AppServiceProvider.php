<?php

namespace App\Providers;

use App\Services\SubscriptionManagerService;
use BezhanSalleh\PanelSwitch\PanelSwitch;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(SubscriptionManagerService::class, function ($app) {
            return new SubscriptionManagerService();
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        PanelSwitch::configureUsing(function (PanelSwitch $panelSwitch) {
            $panelSwitch
                ->slideOver()
                ->visible(fn (): bool => auth()->user()?->hasAnyRole([
/*                     'admin',
                    'developer', */
                    'super_admin',
                ]));
        });
    }
}
