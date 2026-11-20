<?php

declare(strict_types=1);

namespace Inboundr\Laravel\Http\Controllers;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Request;
use Inboundr\Generated\Types;
use Inboundr\Laravel\Events\InboundEmailReceived;
use Inboundr\Laravel\Http\Middleware\VerifyInboundrWebhook;
use Inboundr\Laravel\InboundrManager;
use Symfony\Component\HttpFoundation\Response;

/**
 * Handles a delivery the middleware has verified: skips one already handled,
 * then fires InboundEmailReceived. Answers 204 so Inboundr stops retrying.
 *
 * @phpstan-import-type EmailReceivedEvent from Types
 * @phpstan-import-type Email from Types
 * @phpstan-import-type InboundEmail from InboundEmailReceived
 */
final class WebhookController
{
    /** Seconds an attempt may run before a retry is allowed to start over. */
    private const RUNNING_TTL = 300;

    public function __construct(
        private readonly Config $config,
        private readonly CacheFactory $cache,
        private readonly Dispatcher $events,
        private readonly InboundrManager $inboundr,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $deliveryId = $request->attributes->get(VerifyInboundrWebhook::DELIVERY_ID);
        $deliveryId = is_string($deliveryId) && $deliveryId !== '' ? $deliveryId : null;

        /** @var EmailReceivedEvent|null $event */
        $event = $request->attributes->get(VerifyInboundrWebhook::EVENT);
        $emailId = $request->attributes->get(VerifyInboundrWebhook::EMAIL_ID);
        $emailId = is_string($emailId) && $emailId !== '' ? $emailId : null;
        if ($event === null && $emailId === null) {
            throw new \LogicException('WebhookController needs the VerifyInboundrWebhook middleware in front of it.');
        }

        // Only after verification, so an unsigned request can't claim an id.
        // Test deliveries all share the id "test", so they aren't deduplicated.
        $dedupe = $deliveryId !== null && $deliveryId !== 'test' && (bool) $this->config->get('inboundr.webhook.dedupe', true);
        $store = $dedupe ? $this->cache->store($this->stringConfig('inboundr.webhook.cache_store')) : null;
        $done = "inboundr:delivery:$deliveryId";
        $running = "inboundr:delivery:$deliveryId:running";

        if ($store !== null) {
            if ($store->has($done)) {
                return new Response('', 204);
            }
            // A retry can arrive while the first attempt is still running (the
            // platform gave up waiting). Answer 409 so it is retried later
            // instead of counting as delivered. The marker expires on its own,
            // so a request killed mid-way (timeout, out of memory) can't block
            // the delivery for long.
            if (!$store->add($running, true, self::RUNNING_TTL)) {
                return new Response('Already being handled', 409);
            }
        }

        try {
            if ($event !== null) {
                $email = $event['email'];
            } else {
                // The body was dropped; the middleware verified this id instead.
                $email = self::fromApi($this->inboundr->emails()->get((string) $emailId));
            }
            $this->events->dispatch(new InboundEmailReceived($email, $deliveryId, $event === null));
        } catch (\Throwable $e) {
            // Not handled: let Inboundr's retry through.
            $store?->forget($running);
            throw $e;
        }

        if ($store !== null) {
            $ttl = $this->config->get('inboundr.webhook.dedupe_ttl', 86400);
            $store->put($done, true, is_numeric($ttl) ? (int) $ttl : 86400);
            $store->forget($running);
        }

        return new Response('', 204);
    }

    private function stringConfig(string $key): ?string
    {
        $value = $this->config->get($key);
        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * The API's email, in the webhook payload's shape, so listeners see one shape.
     *
     * @param Email $email
     * @return InboundEmail
     */
    private static function fromApi(array $email): array
    {
        return [
            'id' => $email['id'],
            'threadId' => $email['threadId'],
            'messageId' => $email['messageId'],
            'from' => $email['fromAddress'],
            'to' => $email['toAddresses'],
            'cc' => $email['ccAddresses'],
            'recipients' => $email['recipients'],
            'subject' => $email['subject'],
            'text' => $email['textBody'],
            'html' => $email['htmlBody'],
            'attachments' => array_map(static fn (array $a): array => [
                'filename' => $a['filename'],
                'contentType' => $a['contentType'],
                'size' => $a['size'],
                'downloadUrl' => $a['downloadUrl'],
            ], $email['attachments']),
            'verdicts' => [
                'spam' => $email['spamVerdict'],
                'spf' => $email['spfVerdict'],
                'dkim' => $email['dkimVerdict'],
            ],
            'receivedAt' => $email['receivedAt'],
        ];
    }
}
