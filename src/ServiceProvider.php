<?php

namespace Sellissimo\Mollie;

use Illuminate\Support\Facades\Route;
use Sellissimo\Core\Registry;
use Statamic\Providers\AddonServiceProvider;

class ServiceProvider extends AddonServiceProvider
{
    protected $config = false;

    protected $commands = [ReconcileOrder::class];

    public function register()
    {
        $this->mergeConfigFrom(__DIR__.'/../config/mollie.php', 'sellissimo-mollie');
    }

    public function bootAddon()
    {
        app(Registry::class)->payment('mollie', new Gateway);
        if (! $this->app->routesAreCached()) {
            Route::post('api/sellissimo/mollie/webhook', WebhookController::class)->middleware('throttle:120,1')->name('sellissimo.mollie.webhook');
        }
    }
}
