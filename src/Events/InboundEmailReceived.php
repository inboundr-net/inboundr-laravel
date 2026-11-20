<?php

declare(strict_types=1);

namespace Inboundr\Laravel\Events;

use Inboundr\Generated\Types;

/**
 * Fired once per verified delivery. Listen for it, or make the listener
 * `ShouldQueue` to handle mail off the request.
 *
 * `$email` is the webhook payload's email (`from`, `to`, `subject`, `text`,
 * `html`, `attachments`, `verdicts`, …). When the host dropped the request body,
 * it is fetched through the API instead and `$bodyWasMissing` is true.
 *
 * @phpstan-import-type EmailReceivedEvent from Types
 * @phpstan-type InboundEmail EmailReceivedEvent['email']
 */
final class InboundEmailReceived
{
    /**
     * @param InboundEmail $email
     */
    public function __construct(
        public readonly array $email,
        public readonly ?string $deliveryId,
        public readonly bool $bodyWasMissing = false,
    ) {
    }
}
