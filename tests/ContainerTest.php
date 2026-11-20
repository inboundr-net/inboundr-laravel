<?php

declare(strict_types=1);

namespace Inboundr\Laravel\Tests;

use Inboundr\Inboundr;
use Inboundr\Laravel\Facades\Inboundr as InboundrFacade;
use Inboundr\Laravel\InboundrManager;
use Inboundr\Resources\Emails;

final class ContainerTest extends TestCase
{
    public function testTheClientIsASingletonBuiltFromConfig(): void
    {
        config()->set('inboundr.api_key', 'inb_from_config');
        config()->set('inboundr.base_url', 'https://example.test/');

        $client = $this->app->make(Inboundr::class);
        self::assertSame($client, $this->app->make(Inboundr::class));

        $transport = (new \ReflectionProperty($client->emails, 'transport'))->getValue($client->emails);
        self::assertSame('inb_from_config', (new \ReflectionProperty($transport, 'apiKey'))->getValue($transport));
        self::assertSame('https://example.test/api/v1', (new \ReflectionProperty($transport, 'url'))->getValue($transport));
    }

    public function testTheFacadeReachesTheSameClient(): void
    {
        config()->set('inboundr.api_key', 'inb_x');
        self::assertInstanceOf(Emails::class, InboundrFacade::emails());
        self::assertSame($this->app->make(Inboundr::class), InboundrFacade::client());
        self::assertSame($this->app->make(InboundrManager::class)->emails(), InboundrFacade::emails());
    }

    public function testTheRouteIsNamed(): void
    {
        self::assertSame(url('/inbound'), route('inboundr.webhook'));
    }

    public function testTheConfigIsPublishable(): void
    {
        $paths = \Illuminate\Support\ServiceProvider::pathsToPublish(\Inboundr\Laravel\InboundrServiceProvider::class, 'inboundr-config');
        self::assertCount(1, $paths);
        self::assertFileExists((string) array_key_first($paths));
    }
}
