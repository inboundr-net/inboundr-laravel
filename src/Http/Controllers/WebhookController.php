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
        $store = $this->cache->store($this->stringConfig('inboundr.webhook.cache_store'));
        $key = $deliveryId === null ? null : "inboundr:delivery:$deliveryId";
        if ($key !== null && $this->config->get('inboundr.webhook.dedupe', true)) {
            $ttl = $this->config->get('inboundr.webhook.dedupe_ttl', 86400);
            if (!$store->add($key, true, is_numeric($ttl) ? (int) $ttl : 86400)) {
                return new Response('', 204);
            }
        } else {
            $key = null;
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
            // Let Inboundr's retry through: the delivery wasn't handled.
            if ($key !== null) {
                $store->forget($key);
            }
            throw $e;
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
