<?php

declare(strict_types=1);

namespace Inboundr\Laravel;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Routing\Route as RouteInstance;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Inboundr\Inboundr;

final class InboundrServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/inboundr.php', 'inboundr');

        $this->app->singleton(Inboundr::class, static function (Application $app): Inboundr {
            $config = $app->make(Config::class);
            $key = $config->get('inboundr.api_key');
            $baseUrl = $config->get('inboundr.base_url');
            $timeout = $config->get('inboundr.timeout');
            return new Inboundr(
                apiKey: is_string($key) && $key !== '' ? $key : null,
                baseUrl: is_string($baseUrl) && $baseUrl !== '' ? $baseUrl : Inboundr::DEFAULT_BASE_URL,
                timeout: is_numeric($timeout) ? (float) $timeout : 30.0,
            );
        });

        $this->app->singleton(InboundrManager::class, static fn (Application $app): InboundrManager => new InboundrManager(
            static fn (): Inboundr => $app->make(Inboundr::class),
        ));
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__ . '/../config/inboundr.php' => config_path('inboundr.php')], 'inboundr-config');
        }

        // Not static: Laravel binds macros to the router, and a static
        // closure can't be bound.
        Route::macro('inboundrWebhook', function (string $uri = 'inboundr/webhook', ?string $name = 'inboundr.webhook'): RouteInstance {
            return Routes::webhook($uri, $name);
        });
    }
}
