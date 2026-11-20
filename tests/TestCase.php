<?php

declare(strict_types=1);

namespace Inboundr\Laravel\Tests;

use Illuminate\Support\Facades\Route;
use Inboundr\Laravel\InboundrServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected const SECRET = 'whsec_HXK9y2sVQ4mZ1pTn8LrJdCwB';
    protected const DELIVERY_ID = 'del_4hQx8w';
    protected const EMAIL_ID = 'em_7Kq2mR';

    protected function getPackageProviders($app): array
    {
        return [InboundrServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('inboundr.webhook.secret', self::SECRET);
        $app['config']->set('cache.default', 'array');
        // The `web` group's session and cookie middleware need an app key.
        $app['config']->set('app.key', 'base64:' . base64_encode(str_repeat('k', 32)));
    }

    protected function defineRoutes($router): void
    {
        // Inside `web` on purpose: that group carries CSRF protection, which
        // the route has to switch off or every delivery gets a 419.
        Route::middleware('web')->group(function (): void {
            Route::inboundrWebhook('/inbound');
        });
    }

    /** The webhook payload, as the platform sends it. */
    protected static function body(string $subject = 'Re: invoice 4471'): string
    {
        return json_encode([
            'event' => 'email.received',
            'email' => [
                'id' => self::EMAIL_ID, 'threadId' => 'thr_9Fj4nP', 'messageId' => '<a@b>',
                'from' => 'customer@example.com', 'to' => ['agent@mail.example.com'], 'cc' => [],
                'recipients' => ['agent@mail.example.com'], 'subject' => $subject, 'text' => 'Any update?',
                'html' => null, 'attachments' => [], 'verdicts' => ['spam' => 'PASS', 'spf' => 'PASS', 'dkim' => 'PASS'],
                'receivedAt' => '2026-02-02T03:20:00.000Z',
            ],
        ], JSON_THROW_ON_ERROR);
    }

    /** Signs like the platform: t=<unix>,v1=hex(hmac_sha256(secret, "{t}.{body}")). */
    protected static function sign(string $body, ?int $t = null, string $secret = self::SECRET): string
    {
        $t ??= time();
        return "t=$t,v1=" . hash_hmac('sha256', "$t.$body", $secret);
    }

    protected static function signIds(?int $t = null, string $deliveryId = self::DELIVERY_ID, string $emailId = self::EMAIL_ID): string
    {
        $t ??= time();
        return "t=$t,v1=" . hash_hmac('sha256', "ids.$t.$deliveryId.$emailId", self::SECRET);
    }

    /**
     * @param array<string, string> $headers
     * @return array<string, string>
     */
    protected static function server(array $headers): array
    {
        $server = ['CONTENT_TYPE' => 'application/json'];
        foreach ($headers as $name => $value) {
            $server['HTTP_' . strtoupper(str_replace('-', '_', $name))] = $value;
        }
        return $server;
    }

    /** @param array<string, string> $headers */
    protected function deliver(string $body, array $headers): \Illuminate\Testing\TestResponse
    {
        return $this->call('POST', '/inbound', [], [], [], self::server($headers), $body);
    }

    /** @return array<string, string> */
    protected static function headersFor(string $body, string $deliveryId = self::DELIVERY_ID): array
    {
        return [
            'x-inboundr-signature' => self::sign($body),
            'x-inboundr-delivery-id' => $deliveryId,
            'x-inboundr-email-id' => self::EMAIL_ID,
            'x-inboundr-id-signature' => self::signIds(deliveryId: $deliveryId),
        ];
    }
}
