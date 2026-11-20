<?php

declare(strict_types=1);

namespace Inboundr\Laravel;

use Inboundr\Inboundr;
use Inboundr\Resources\Addresses;
use Inboundr\Resources\Domains;
use Inboundr\Resources\Emails;
use Inboundr\Resources\Endpoints;
use Inboundr\Resources\ForwardDestinations;
use Inboundr\Resources\Senders;
use Inboundr\Resources\ShieldRules;
use Inboundr\Resources\Suppressions;
use Inboundr\Resources\Threads;

/**
 * What the `Inboundr` facade calls. Facades forward method calls, and the
 * client exposes its resources as properties, so this turns `->emails` into
 * `emails()`. The client is built only when first used, so an app without an
 * API key can still receive webhooks.
 */
final class InboundrManager
{
    /** @param \Closure(): Inboundr $factory */
    public function __construct(private readonly \Closure $factory)
    {
    }

    private ?Inboundr $client = null;

    public function client(): Inboundr
    {
        return $this->client ??= ($this->factory)();
    }

    public function emails(): Emails
    {
        return $this->client()->emails;
    }

    public function threads(): Threads
    {
        return $this->client()->threads;
    }

    public function domains(): Domains
    {
        return $this->client()->domains;
    }

    public function addresses(): Addresses
    {
        return $this->client()->addresses;
    }

    public function endpoints(): Endpoints
    {
        return $this->client()->endpoints;
    }

    public function shieldRules(): ShieldRules
    {
        return $this->client()->shieldRules;
    }

    public function senders(): Senders
    {
        return $this->client()->senders;
    }

    public function suppressions(): Suppressions
    {
        return $this->client()->suppressions;
    }

    public function forwardDestinations(): ForwardDestinations
    {
        return $this->client()->forwardDestinations;
    }
}
