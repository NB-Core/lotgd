<?php

declare(strict_types=1);

namespace Lotgd\Tests\Security;

use PHPUnit\Framework\TestCase;

/**
 * Lightweight source-level regression check that parameter binding remains in place.
 */
final class PaymentIpnHardeningRegressionTest extends TestCase
{
    public function testPaymentProcessorUsesParameterizedDbalCalls(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Lotgd/Payment/IpnPaymentProcessor.php');

        self::assertStringContainsString('ParameterType::STRING', $source);
        self::assertStringContainsString('ParameterType::INTEGER', $source);
        self::assertStringContainsString('executeStatement(', $source);
        self::assertStringContainsString('fetchAssociative(', $source);
    }
}
