<?php

declare(strict_types=1);

namespace Inboundr\Laravel\Facades;

use Illuminate\Support\Facades\Facade;
use Inboundr\Laravel\InboundrManager;

/**
 *     Inboundr::emails()->list(['direction' => 'inbound']);
 *
 * @method static \Inboundr\Inboundr client()
 * @method static \Inboundr\Resources\Emails emails()
 * @method static \Inboundr\Resources\Threads threads()
 * @method static \Inboundr\Resources\Domains domains()
 * @method static \Inboundr\Resources\Addresses addresses()
 * @method static \Inboundr\Resources\Endpoints endpoints()
 * @method static \Inboundr\Resources\ShieldRules shieldRules()
 * @method static \Inboundr\Resources\Senders senders()
 * @method static \Inboundr\Resources\Suppressions suppressions()
 * @method static \Inboundr\Resources\ForwardDestinations forwardDestinations()
 *
 * @see InboundrManager
 */
final class Inboundr extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return InboundrManager::class;
    }
}
