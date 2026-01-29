<?php

namespace Lagdo\Facades\Tests;

use Lagdo\Facades\ContainerWrapper;
use Lagdo\Facades\Logger;
use League\Container\Container;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Error;
use Exception;

use function is_a;

/**
 * Test the logger service.
 */
class ProvidedServiceTest extends TestCase
{
    private Container $container;

    protected function setUp(): void
    {
        $this->container = new Container();
        $this->container->add(LoggerInterface::class, NullLogger::class);
        ContainerWrapper::setContainer($this->container);
    }

    public function testService()
    {
        // The logger service is defined.
        $this->assertTrue($this->container->has(LoggerInterface::class));
    }

    public function testLoggerFacade()
    {
        $serviceFound = false;
        try
        {
            Logger::debug('The logger facade works!');
            $serviceFound = true;
        }
        catch(Error $e){}
        catch(Exception $e){}
        $this->assertTrue($serviceFound);

        // Test the service class
        $this->assertTrue(is_a(Logger::instance(), LoggerInterface::class));
    }
}
