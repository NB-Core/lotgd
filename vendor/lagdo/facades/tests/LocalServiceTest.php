<?php

namespace Lagdo\Facades\Tests;

use Lagdo\Facades\ContainerWrapper;
use Lagdo\Facades\Logger;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Error;
use Exception;

use function is_a;

/**
 * Test the logger service.
 */
class LocalServiceTest extends TestCase
{
    protected function setUp(): void
    {
        ContainerWrapper::registerLocalServices([
            'filename' => __DIR__ . '/logs/test',
        ]);
    }

    public function testService()
    {
        // The logger service is defined.
        $this->assertTrue(ContainerWrapper::getContainer()->has(LoggerInterface::class));
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
