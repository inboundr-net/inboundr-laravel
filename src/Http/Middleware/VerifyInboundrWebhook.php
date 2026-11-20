<?php

declare(strict_types=1);

namespace Inboundr\Laravel\Http\Middleware;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Http\Request;
use Inboundr\Exception\SignatureException;
use Inboundr\Webhook;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rejects any request that isn't a verified Inboundr delivery with a 400, and
 * puts what was verified on the request for the next handler:
 *
 * - `inboundr.event`: the event array, when the body arrived;
 * - `inboundr.email_id`: the signed email id, when the host dropped the body;
 * - `inboundr.delivery_id`: the delivery id, checked against its signature,
 *   for deduplication.
 *
 * Usable on your own route as `->middleware(VerifyInboundrWebhook::class)`.
 */
final class VerifyInboundrWebhook
{
    public const EVENT = 'inboundr.event';
    public const EMAIL_ID = 'inboundr.email_id';
    public const DELIVERY_ID = 'inboundr.delivery_id';

    public function __construct(private readonly Config $config)
    {
    }

    /** @param \Closure(Request): Response $next */
    public function handle(Request $request, \Closure $next): Response
    {
        $secret = $this->config->get('inboundr.webhook.secret');
        if (!is_string($secret) || $secret === '') {
            // A deploy mistake, not a bad request: fail loudly so it gets fixed.
            throw new \RuntimeException('Set INBOUNDR_WEBHOOK_SECRET (config inboundr.webhook.secret) to verify Inboundr webhooks.');
        }
        $tolerance = $this->config->get('inboundr.webhook.tolerance', Webhook::DEFAULT_TOLERANCE_SECONDS);
        $tolerance = is_numeric($tolerance) ? (int) $tolerance : Webhook::DEFAULT_TOLERANCE_SECONDS;

        try {
            $event = Webhook::verify($request->getContent(), $request->headers, $secret, $tolerance);
        } catch (SignatureException $e) {
            if ($e->reason !== 'missing_body') {
                return new Response('Invalid Inboundr signature', 400);
            }
            $event = null;
        }

        // The body signature doesn't cover the delivery id, so a replayed
        // delivery could carry any id and slip past deduplication. The id
        // signature does: require it, and that it names the body's email.
        try {
            $ids = Webhook::verifyIds($request->headers, $secret, $tolerance);
        } catch (SignatureException) {
            return new Response('Invalid Inboundr signature', 400);
        }
        if ($event !== null && $ids->emailId !== $event['email']['id']) {
            return new Response('Invalid Inboundr signature', 400);
        }

        if ($event !== null) {
            $request->attributes->set(self::EVENT, $event);
        } else {
            $request->attributes->set(self::EMAIL_ID, $ids->emailId);
        }
        $request->attributes->set(self::DELIVERY_ID, $ids->deliveryId);

        return $next($request);
    }
}
