<?php

declare(strict_types=1);

namespace Inboundr\Laravel;

use Illuminate\Routing\Route as RouteInstance;
use Illuminate\Support\Facades\Route;
use Inboundr\Laravel\Http\Controllers\WebhookController;
use Inboundr\Laravel\Http\Middleware\VerifyInboundrWebhook;

/**
 * Registers the webhook route. `Route::inboundrWebhook()` calls this; use it
 * directly where static analysis can't see Laravel macros:
 *
 *     \Inboundr\Laravel\Routes::webhook('/inbound');
 */
final class Routes
{
    /**
     * CSRF middleware across Laravel 12 and 13. A webhook carries no CSRF token,
     * so a route in the `web` group would answer every delivery with a 419.
     */
    public const CSRF_MIDDLEWARE = [
        'Illuminate\Foundation\Http\Middleware\PreventRequestForgery',
        'Illuminate\Foundation\Http\Middleware\ValidateCsrfToken',
        'Illuminate\Foundation\Http\Middleware\VerifyCsrfToken',
    ];

    public static function webhook(string $uri = 'inboundr/webhook'): RouteInstance
    {
        return Route::post($uri, WebhookController::class)
            ->middleware(VerifyInboundrWebhook::class)
            ->withoutMiddleware(self::CSRF_MIDDLEWARE)
            ->name('inboundr.webhook');
    }
}
