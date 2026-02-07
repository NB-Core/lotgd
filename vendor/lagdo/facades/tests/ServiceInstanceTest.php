<?php

namespace Lagdo\Facades\Tests;

use Lagdo\Facades\ContainerWrapper;
use Lagdo\Facades\Tests\Facades\Instance\ServiceFacade;
use Lagdo\Facades\Tests\Service\Service;
use Lagdo\Facades\Tests\Service\ServiceInterface;
use League\Container\Container;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

use function is_a;

/**
 * Test the ServiceInstance trait.
 * The service container must be called only once for each service.
 */
class ServiceInstanceTest extends TestCase
{
    private Container $container;

    protected function setUp(): void
    {
        $this->container = new Container();
        $this->container->add(NullLogger::class);
        $this->container->add(ServiceInterface::class, Service::class)
            ->addArgument(NullLogger::class);
        ContainerWrapper::setContainer($this->container);
    }

    public function testService()
    {
        $this->assertTrue($this->container->has(ServiceInterface::class));
    }

    public function testServiceFacade()
    {
        // Test the service class
        $this->assertTrue(is_a(ServiceFacade::instance(), ServiceInterface::class));

        // Test the service class
        $this->assertTrue(is_a(ServiceFacade::instance(), ServiceInterface::class));

        // The container is called once
        $this->assertEquals(1, ServiceFacade::$callCount);
    }
}
