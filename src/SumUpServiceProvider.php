<?php

namespace FLAIRUK\SumUp;

use FLAIRUK\SumUp\Http\Controllers\WebhookController;
use FLAIRUK\SumUp\View\Components\CardWidget;
use FLAIRUK\SumUp\Webhooks\WebhookHandler;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class SumUpServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/sumup.php', 'sumup');

        $this->app->singleton(SumUp::class, fn (Application $app) => new SumUp(
            $app->make(Http::class),
            $app['config']->get('sumup'),
        ));

        $this->app->alias(SumUp::class, 'sumup');

        $this->app->singleton(WebhookHandler::class);
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'sumup');

        Blade::component('sumup-card', CardWidget::class);

        $this->registerWebhookRoute();

        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__.'/../config/sumup.php' => config_path('sumup.php'),
        ], 'sumup-config');

        $this->commands([
            Console\InstallCommand::class,
            Console\StatusCommand::class,
        ]);
    }

    protected function registerWebhookRoute(): void
    {
        if (! $this->app['config']->get('sumup.webhooks.enabled') || $this->app->routesAreCached()) {
            return;
        }

        Route::post($this->app['config']->get('sumup.webhooks.path'), WebhookController::class)
            ->middleware($this->app['config']->get('sumup.webhooks.middleware', []))
            ->name('sumup.webhook');
    }
}
