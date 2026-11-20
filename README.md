![Inboundr](https://raw.githubusercontent.com/inboundr-net/inboundr-laravel/refs/heads/main/assets/banner.png)

# Inboundr for Laravel

[![Packagist](https://img.shields.io/packagist/v/inboundr/inboundr-laravel?color=0f766e&label=packagist)](https://packagist.org/packages/inboundr/inboundr-laravel)
[![license](https://img.shields.io/badge/license-MIT-0f766e)](./LICENSE)

Laravel integration for [Inboundr](https://inboundr.net): a webhook route that verifies each delivery and fires an event, and the API client from the container. Built on [inboundr/inboundr](https://github.com/inboundr-net/inboundr-php).

- [Quickstart](https://inboundr.net/docs/quickstart)
- [API reference](https://inboundr.net/docs/api-reference/introduction)

## Install

```bash
composer require inboundr/inboundr-laravel
```

Laravel 12 or 13, PHP 8.2+.

Add your keys to `.env`:

```bash
INBOUNDR_API_KEY=inb_123456789
INBOUNDR_WEBHOOK_SECRET=whsec_123456789
```

The webhook secret is the `whsec_` value returned when you create the endpoint. The API key is only needed for calling the API.

## Receive email

Register the route in `routes/web.php`:

```php
Route::inboundrWebhook('/inbound');
```

Point your Inboundr endpoint at `https://your-app.com/inbound`. Every delivery is verified before anything runs; anything that fails gets a `400`. The route skips the `web` group's CSRF, session and cookie middleware: a webhook can't pass CSRF and has no session, so none is started or stored per email.

Then listen for the event:

```php
use Inboundr\Laravel\Events\InboundEmailReceived;

class HandleInboundEmail
{
    public function handle(InboundEmailReceived $event): void
    {
        $email = $event->email;

        logger()->info("{$email['from']}: {$email['subject']}");
    }
}
```

Laravel discovers listeners in `app/Listeners` automatically. Implement `ShouldQueue` to handle mail on a queue instead of in the request; see [Retries](#retries) for what changes when you do.

`$event->email` has `id`, `threadId`, `from`, `to`, `cc`, `subject`, `text`, `html`, `attachments`, `verdicts` and `receivedAt`.

### Retries

Inboundr retries a delivery until it gets a `2xx`. Once the event has been handled, the route remembers the delivery id for a day and answers repeats with `204` without firing it again. The id is checked against its signature, so a replayed delivery can't pass as a new one.

- **Listeners that run in the request:** if one throws, the route answers `500` and Inboundr's retry runs it again. If the request dies (a timeout, out of memory), the retry is let through after 5 minutes. A retry that arrives while the first attempt is still running gets a `409` and is tried again later.
- **Queued listeners (`ShouldQueue`):** the route answers `204` once the job is queued, so Inboundr won't retry. A job that fails is retried by your queue worker, using the job's `$tries` and `backoff`, not by Inboundr.

Test deliveries sent from the console all share the delivery id `test`, so they are never skipped.

### Missing body

Some hosts drop the request body. The delivery still carries a signed email id; the route verifies it, fetches the email through the API (this needs `INBOUNDR_API_KEY`) and fires the same event with `$event->bodyWasMissing` set to `true`.

## Call the API

Type-hint the client anywhere the container resolves:

```php
use Inboundr\Inboundr;

public function __construct(private Inboundr $inboundr) {}

$this->inboundr->emails->reply($emailId, ['text' => "Thanks, we're on it."]);
```

Or use the facade:

```php
use Inboundr\Laravel\Facades\Inboundr;

$page = Inboundr::emails()->list(['direction' => 'inbound', 'limit' => 20]);
```

Every method of the [PHP SDK](https://github.com/inboundr-net/inboundr-php#resources) is available.

## Configuration

To change the defaults, publish the config file:

```bash
php artisan vendor:publish --tag=inboundr-config
```

| Key | Default | |
| --- | --- | --- |
| `api_key` | `INBOUNDR_API_KEY` | |
| `base_url` | `https://inboundr.net` | |
| `timeout` | `30` | Seconds. |
| `webhook.secret` | `INBOUNDR_WEBHOOK_SECRET` | |
| `webhook.tolerance` | `300` | Maximum signature age, in seconds. |
| `webhook.dedupe` | `true` | Skip deliveries already handled. |
| `webhook.dedupe_ttl` | `86400` | How long to remember a delivery id, in seconds. |
| `webhook.cache_store` | default store | Cache store for delivery ids. |

## Your own controller

To handle deliveries yourself, use the middleware on your route. It answers `400` to anything unverified and puts what it verified on the request:

```php
use Inboundr\Laravel\Http\Middleware\VerifyInboundrWebhook;
use Inboundr\Laravel\Routes;

Route::post('/inbound', InboundController::class)
    ->middleware(VerifyInboundrWebhook::class)
    ->withoutMiddleware([...Routes::CSRF_MIDDLEWARE, ...Routes::SESSION_MIDDLEWARE]);
```

```php
use Inboundr\Laravel\Facades\Inboundr;

public function __invoke(Request $request)
{
    $event = $request->attributes->get(VerifyInboundrWebhook::EVENT);
    $deliveryId = $request->attributes->get(VerifyInboundrWebhook::DELIVERY_ID); // signed

    if ($event === null) {
        // The host dropped the body; only the signed email id arrived.
        $email = Inboundr::emails()->get($request->attributes->get(VerifyInboundrWebhook::EMAIL_ID));
    }

    // ...
    return response()->noContent();
}
```

Handle both cases: on a host that drops request bodies, `EVENT` is not set, and answering `2xx` without fetching the email would lose it. The fetched email uses the API's field names (`fromAddress`, `textBody`, …), unlike the event's (`from`, `text`, …).

`Route::inboundrWebhook()` is a macro, which static analysis can't see. `\Inboundr\Laravel\Routes::webhook('/inbound')` does the same and is fully typed. Both take a route name as a second argument (`inboundr.webhook` by default), for registering more than one webhook URL.

## Contributing

See [CONTRIBUTING.md](./CONTRIBUTING.md).

## License

MIT
