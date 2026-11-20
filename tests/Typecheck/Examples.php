<?php

declare(strict_types=1);

namespace Inboundr\Laravel\Tests\Typecheck;

use Illuminate\Contracts\Queue\ShouldQueue;
use Inboundr\Inboundr;
use Inboundr\Laravel\Events\InboundEmailReceived;
use Inboundr\Laravel\Facades\Inboundr as InboundrFacade;
use Inboundr\Laravel\Routes;

/** Analysed by PHPStan (level max), never run: the usage the README shows. */
final class HandleInboundEmail implements ShouldQueue
{
    public function __construct(private readonly Inboundr $inboundr)
    {
    }

    public function handle(InboundEmailReceived $event): void
    {
        $from = $event->email['from'];
        $subject = $event->email['subject'] ?? '(no subject)';
        $this->inboundr->emails->reply($event->email['id'], ['text' => "Thanks, $from. Re: $subject"]);
        InboundrFacade::emails()->get($event->email['id']);
    }
}

function routes(): void
{
    Routes::webhook('/inbound');
}
