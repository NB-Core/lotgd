<?php

namespace Lagdo\Facades\Tests;

use Lagdo\Facades\ContainerWrapper;
use Lagdo\Facades\Tests\Facades\ServiceFacade;
use Lagdo\Facades\Tests\Service\ServiceInterface;
use League\Container\Container;
use PHPUnit\Framework\TestCase;
use Psr\Container\NotFoundExceptionInterface;

/**
 * Test access to an undefined service.
 */
class ServiceUndefinedTest extends TestCase
{
    private Container $container;

    protected function setUp(): void
    {
        $this->container = new Container();
        ContainerWrapper::setContainer($this->container);
    }

    public function testService()
    {
        // The public service can be read from the container.
        $this->assertFalse($this->container->has(ServiceInterface::class));
    }

    public function testServiceFacade()
    {
        $this->expectException(NotFoundExceptionInterface::class);

        ServiceFacade::log('Container');
    }

    public function testServiceInstance()
    {
        $this->expectException(NotFoundExceptionInterface::class);

        $service = ServiceFacade::instance();
    }
}
