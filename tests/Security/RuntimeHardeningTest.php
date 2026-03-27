<?php

declare(strict_types=1);

namespace Lotgd\Tests\Security;

use Lotgd\Security\RuntimeHardening;
use PHPUnit\Framework\TestCase;

/**
 * Regression tests for RuntimeHardening HTTPS detection.
 */
final class RuntimeHardeningTest extends TestCase
{
    public function testIsHttpsRequestUnderstandsForwardedProto(): void
    {
        $trustedOptions = [
            'SECURITY_TRUST_FORWARDED_PROTO' => true,
            'SECURITY_TRUSTED_PROXIES' => '127.0.0.1,10.0.0.1',
        ];

        self::assertTrue(RuntimeHardening::isHttpsRequest([
            'HTTP_X_FORWARDED_PROTO' => 'https',
            'REMOTE_ADDR' => '127.0.0.1',
        ], $trustedOptions));

        self::assertTrue(RuntimeHardening::isHttpsRequest([
            'HTTP_X_FORWARDED_PROTO' => 'https,http',
            'REMOTE_ADDR' => '127.0.0.1',
        ], $trustedOptions));

        self::assertTrue(RuntimeHardening::isHttpsRequest([
            'HTTP_FORWARDED_PROTO' => 'https',
            'REMOTE_ADDR' => '127.0.0.1',
        ], $trustedOptions));

        self::assertFalse(RuntimeHardening::isHttpsRequest([
            'HTTP_X_FORWARDED_PROTO' => 'https',
            'REMOTE_ADDR' => '192.168.1.20',
            'HTTPS' => 'off',
            'SERVER_PORT' => 80,
        ], $trustedOptions));
    }

    public function testIsHttpsRequestFallsBackToNativeHttpsSignals(): void
    {
        self::assertTrue(RuntimeHardening::isHttpsRequest(['HTTPS' => 'on']));
        self::assertTrue(RuntimeHardening::isHttpsRequest(['SERVER_PORT' => 443]));
        self::assertFalse(RuntimeHardening::isHttpsRequest(['HTTPS' => 'off', 'SERVER_PORT' => 80]));
    }
}
