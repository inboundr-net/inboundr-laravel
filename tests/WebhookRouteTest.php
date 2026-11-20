<?php

declare(strict_types=1);

namespace Inboundr\Laravel\Tests;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Inboundr\Inboundr;
use Inboundr\Laravel\Events\InboundEmailReceived;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

final class WebhookRouteTest extends TestCase
{
    public function testAVerifiedDeliveryFiresTheEventAndAnswers204(): void
    {
        Event::fake([InboundEmailReceived::class]);
        $body = self::body();

        $this->deliver($body, self::headersFor($body))->assertNoContent();

        Event::assertDispatchedTimes(InboundEmailReceived::class, 1);
        Event::assertDispatched(InboundEmailReceived::class, fn (InboundEmailReceived $e): bool => $e->email['id'] === self::EMAIL_ID
            && $e->email['from'] === 'customer@example.com'
            && $e->deliveryId === self::DELIVERY_ID
            && $e->bodyWasMissing === false);
    }

    public function testTheRouteSkipsCsrfInsideTheWebGroup(): void
    {
        // Laravel's CSRF middleware turns itself off under unit tests, so a
        // request here would pass either way. Check what the router would
        // actually run for this route, with the `web` group expanded. The
        // kernel hands its groups to the router on the first request.
        Event::fake([InboundEmailReceived::class]);
        $body = self::body();
        $this->deliver($body, self::headersFor($body))->assertNoContent();
        $route = app('router')->getRoutes()->getByName('inboundr.webhook');
        self::assertNotNull($route);
        $middleware = app('router')->gatherRouteMiddleware($route);

        $named = static fn (array $classes): array => array_values(array_filter($middleware, static function (mixed $m) use ($classes): bool {
            if (!is_string($m)) {
                return false;
            }
            $class = explode(':', $m)[0];
            foreach ($classes as $c) {
                if (class_exists($c) && is_a($class, $c, true)) {
                    return true;
                }
            }
            return false;
        }));
        // Every CSRF class across Laravel 12 and 13, not only 13's name.
        self::assertSame([], $named(\Inboundr\Laravel\Routes::CSRF_MIDDLEWARE), 'CSRF middleware still runs on the webhook route');
        self::assertSame([], $named(\Inboundr\Laravel\Routes::SESSION_MIDDLEWARE), 'session middleware still runs on the webhook route');
        self::assertContains(\Inboundr\Laravel\Http\Middleware\VerifyInboundrWebhook::class, $middleware);
    }

    public function testABadSignatureIs400AndFiresNothing(): void
    {
        Event::fake([InboundEmailReceived::class]);
        $body = self::body();
        $headers = ['x-inboundr-signature' => self::sign($body, secret: 'whsec_other')] + self::headersFor($body);

        $this->deliver($body, $headers)->assertStatus(400);
        $this->deliver($body, [])->assertStatus(400);
        $this->deliver(str_replace('4471', '4472', $body), self::headersFor($body))->assertStatus(400);

        Event::assertNotDispatched(InboundEmailReceived::class);
    }

    public function testAStaleSignatureIs400(): void
    {
        Event::fake([InboundEmailReceived::class]);
        $body = self::body();
        $this->deliver($body, ['x-inboundr-signature' => self::sign($body, time() - 3600)])->assertStatus(400);
        Event::assertNotDispatched(InboundEmailReceived::class);
    }

    public function testTheLegacyHeaderNamesWork(): void
    {
        Event::fake([InboundEmailReceived::class]);
        $body = self::body();
        $this->deliver($body, [
            'x-inbound-signature' => self::sign($body),
            'x-inbound-delivery-id' => 'del_legacy',
            'x-inboundr-email-id' => self::EMAIL_ID,
            'x-inboundr-id-signature' => self::signIds(deliveryId: 'del_legacy'),
        ])->assertNoContent();
        Event::assertDispatched(InboundEmailReceived::class, fn (InboundEmailReceived $e): bool => $e->deliveryId === 'del_legacy');
    }

    public function testARetriedDeliveryIsHandledOnce(): void
    {
        Event::fake([InboundEmailReceived::class]);
        $body = self::body();

        $this->deliver($body, self::headersFor($body))->assertNoContent();
        $this->deliver($body, self::headersFor($body))->assertNoContent();
        $this->deliver($body, self::headersFor($body, 'del_other'))->assertNoContent();

        Event::assertDispatchedTimes(InboundEmailReceived::class, 2);
    }

    public function testDedupeCanBeTurnedOff(): void
    {
        config()->set('inboundr.webhook.dedupe', false);
        Event::fake([InboundEmailReceived::class]);
        $body = self::body();
        $this->deliver($body, self::headersFor($body))->assertNoContent();
        $this->deliver($body, self::headersFor($body))->assertNoContent();
        Event::assertDispatchedTimes(InboundEmailReceived::class, 2);
    }

    public function testAFailingListenerLetsTheRetryThrough(): void
    {
        $calls = 0;
        Event::listen(InboundEmailReceived::class, function () use (&$calls): void {
            if (++$calls === 1) {
                throw new \RuntimeException('database down');
            }
        });
        $body = self::body();

        $this->deliver($body, self::headersFor($body))->assertStatus(500);
        self::assertFalse(Cache::has('inboundr:delivery:' . self::DELIVERY_ID));
        $this->deliver($body, self::headersFor($body))->assertNoContent();

        self::assertSame(2, $calls);
    }

    public function testAForgedRequestDoesNotClaimItsDeliveryId(): void
    {
        Event::fake([InboundEmailReceived::class]);
        $body = self::body();
        $this->deliver($body, ['x-inboundr-signature' => 't=1,v1=00'] + self::headersFor($body))->assertStatus(400);
        self::assertFalse(Cache::has('inboundr:delivery:' . self::DELIVERY_ID));
        $this->deliver($body, self::headersFor($body))->assertNoContent();
        Event::assertDispatchedTimes(InboundEmailReceived::class, 1);
    }

    public function testAMissingSecretFailsLoudly(): void
    {
        config()->set('inboundr.webhook.secret', null);
        $this->withoutExceptionHandling();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('INBOUNDR_WEBHOOK_SECRET');
        $body = self::body();
        $this->deliver($body, self::headersFor($body));
    }

    public function testADroppedBodyIsFetchedThroughTheApi(): void
    {
        Event::fake([InboundEmailReceived::class]);
        $requests = [];
        $this->app->instance(Inboundr::class, self::clientAnswering(function (RequestInterface $r) use (&$requests): ResponseInterface {
            $requests[] = $r;
            return new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'id' => self::EMAIL_ID, 'domainId' => 'dom_1', 'direction' => 'inbound', 'messageId' => '<a@b>',
                'inReplyTo' => null, 'referencesHeader' => null, 'threadId' => 'thr_1', 'fromAddress' => 'customer@example.com',
                'toAddresses' => ['agent@x.dev'], 'ccAddresses' => [], 'replyToAddresses' => [], 'senderId' => null,
                'recipients' => ['agent@x.dev'], 'subject' => 'From the API', 'textBody' => 'hello', 'htmlBody' => null,
                'attachments' => [['filename' => 'a.pdf', 'contentType' => 'application/pdf', 'size' => 3, 'stored' => true, 'downloadUrl' => 'https://x/a']],
                'spamVerdict' => 'PASS', 'spfVerdict' => 'PASS', 'dkimVerdict' => 'FAIL', 'deliveryStatus' => null, 'sendStatus' => null,
                'receivedAt' => '2026-02-02T03:20:00.000Z', 'createdAt' => '2026-02-02T03:20:00.000Z',
            ], JSON_THROW_ON_ERROR));
        }));

        $this->deliver('', self::headersFor('') )->assertNoContent();

        self::assertStringEndsWith('/api/v1/emails/' . self::EMAIL_ID, $requests[0]->getUri()->getPath());
        Event::assertDispatched(InboundEmailReceived::class, fn (InboundEmailReceived $e): bool => $e->bodyWasMissing
            && $e->email['from'] === 'customer@example.com'
            && $e->email['subject'] === 'From the API'
            && $e->email['text'] === 'hello'
            && $e->email['verdicts']['dkim'] === 'FAIL'
            && $e->email['attachments'][0]['downloadUrl'] === 'https://x/a');
    }

    public function testADroppedBodyWithABadIdSignatureIs400(): void
    {
        Event::fake([InboundEmailReceived::class]);
        $headers = ['x-inboundr-id-signature' => 't=1,v1=00'] + self::headersFor('');
        $this->deliver('', $headers)->assertStatus(400);
        Event::assertNotDispatched(InboundEmailReceived::class);
    }

    public function testTheWebhookWorksWithoutAnApiKey(): void
    {
        config()->set('inboundr.api_key', null);
        putenv('INBOUNDR_API_KEY');
        Event::fake([InboundEmailReceived::class]);
        $body = self::body();
        $this->deliver($body, self::headersFor($body))->assertNoContent();
        Event::assertDispatchedTimes(InboundEmailReceived::class, 1);
    }

    // --- delivery ids must be signed ---------------------------------------------------

    public function testAReplayWithAForgedDeliveryIdIsRejected(): void
    {
        Event::fake([InboundEmailReceived::class]);
        $body = self::body();
        $this->deliver($body, self::headersFor($body))->assertNoContent();
        // The captured delivery again, with a new delivery id to dodge dedupe.
        $this->deliver($body, ['x-inboundr-delivery-id' => 'del_forged'] + self::headersFor($body))->assertStatus(400);
        Event::assertDispatchedTimes(InboundEmailReceived::class, 1);
    }

    public function testAReplayWithoutTheIdSignatureIsRejected(): void
    {
        Event::fake([InboundEmailReceived::class]);
        $body = self::body();
        $this->deliver($body, ['x-inboundr-signature' => self::sign($body)])->assertStatus(400);
        Event::assertNotDispatched(InboundEmailReceived::class);
    }

    public function testIdHeadersNamingAnotherEmailAreRejected(): void
    {
        Event::fake([InboundEmailReceived::class]);
        $body = self::body();
        $headers = [
            'x-inboundr-signature' => self::sign($body),
            'x-inboundr-delivery-id' => 'del_2',
            'x-inboundr-email-id' => 'em_other',
            'x-inboundr-id-signature' => self::signIds(deliveryId: 'del_2', emailId: 'em_other'),
        ];
        $this->deliver($body, $headers)->assertStatus(400);
        Event::assertNotDispatched(InboundEmailReceived::class);
    }

    // --- dedupe that can't lose a delivery -------------------------------------------

    public function testARetryWhileTheFirstAttemptIsRunningGets409(): void
    {
        $body = self::body();
        $second = null;
        Event::listen(InboundEmailReceived::class, function () use (&$second, $body): void {
            // The platform retries while this attempt is still running.
            $second ??= $this->deliver($body, self::headersFor($body))->getStatusCode();
        });
        $this->deliver($body, self::headersFor($body))->assertNoContent();
        self::assertSame(409, $second);
    }

    public function testAnAttemptKilledMidWayDoesNotBlockTheRetryForADay(): void
    {
        // Faked first: Laravel keeps a route's controller between requests,
        // with the dispatcher it was built with.
        Event::fake([InboundEmailReceived::class]);
        // A fatal error (timeout, out of memory) skips every catch: all that is
        // left is the short-lived marker, never the 24h "handled" mark.
        Cache::put('inboundr:delivery:' . self::DELIVERY_ID . ':running', true, 300);
        self::assertFalse(Cache::has('inboundr:delivery:' . self::DELIVERY_ID));
        $this->deliver(self::body(), self::headersFor(self::body()))->assertStatus(409);

        $this->travel(301)->seconds();
        $body = self::body();
        $this->deliver($body, self::headersFor($body))->assertNoContent();
        Event::assertDispatchedTimes(InboundEmailReceived::class, 1);
    }

    public function testHandledIsOnlyRecordedAfterTheListenerSucceeds(): void
    {
        $seenDuring = null;
        Event::listen(InboundEmailReceived::class, function () use (&$seenDuring): void {
            $seenDuring = Cache::has('inboundr:delivery:' . self::DELIVERY_ID);
        });
        $body = self::body();
        $this->deliver($body, self::headersFor($body))->assertNoContent();
        self::assertFalse($seenDuring);
        self::assertTrue(Cache::has('inboundr:delivery:' . self::DELIVERY_ID));
        self::assertFalse(Cache::has('inboundr:delivery:' . self::DELIVERY_ID . ':running'));
    }

    public function testTestDeliveriesAreNotDeduplicated(): void
    {
        Event::fake([InboundEmailReceived::class]);
        $body = self::body();
        $this->deliver($body, self::headersFor($body, 'test'))->assertNoContent();
        $this->deliver($body, self::headersFor($body, 'test'))->assertNoContent();
        Event::assertDispatchedTimes(InboundEmailReceived::class, 2);
    }

    public function testABrokenCacheStoreDoesNotMatterWhenDedupeIsOff(): void
    {
        config()->set('inboundr.webhook.dedupe', false);
        config()->set('inboundr.webhook.cache_store', 'not-a-store');
        Event::fake([InboundEmailReceived::class]);
        $body = self::body();
        $this->deliver($body, self::headersFor($body))->assertNoContent();
        Event::assertDispatchedTimes(InboundEmailReceived::class, 1);
    }

    /** @param \Closure(RequestInterface): ResponseInterface $handler */
    private static function clientAnswering(\Closure $handler): Inboundr
    {
        $http = new class ($handler) implements ClientInterface {
            /** @param \Closure(RequestInterface): ResponseInterface $handler */
            public function __construct(private readonly \Closure $handler)
            {
            }

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                return ($this->handler)($request);
            }
        };
        $factory = new Psr17Factory();
        return new Inboundr('inb_test', httpClient: $http, requestFactory: $factory, streamFactory: $factory);
    }
}
