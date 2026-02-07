<?php

namespace Lagdo\Facades\Tests;

use Lagdo\Facades\ContainerWrapper;
use Lagdo\Facades\Tests\Facades\ServiceFacade;
use Lagdo\Facades\Tests\Service\Service;
use Lagdo\Facades\Tests\Service\ServiceInterface;
use League\Container\Container;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Error;
use Exception;

use function is_a;

/**
 * Test the basic service container.
 */
class ServiceContainerTest extends TestCase
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
        // The public service can be read from the container.
        $this->assertTrue($this->container->has(ServiceInterface::class));
    }

    public function testServiceFacade()
    {
        $serviceFound = false;
        try
        {
            ServiceFacade::log('Container');
            $serviceFound = true;
        }
        catch(Error $e){}
        catch(Exception $e){}
        $this->assertTrue($serviceFound);

        // Test the service class
        $this->assertTrue(is_a(ServiceFacade::instance(), ServiceInterface::class));

        // Test the service class
        $this->assertNotNull(ServiceFacade::instance());
    }
}
