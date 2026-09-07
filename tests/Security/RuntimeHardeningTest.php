<?php

declare(strict_types=1);

namespace Lotgd\Tests\Security;

use Lotgd\Security\RuntimeHardening;
use PHPUnit\Framework\TestCase;

/**
 * Regression tests for RuntimeHardening HTTPS detection.
 */

class RuntimeHardeningTest extends TestCase
{
    public function testBuildSessionCookieParamsUsesHttpsAndStrictSameSite(): void
    {
        $options = RuntimeHardening::buildOptions([
            'SESSION_COOKIE_PATH' => '/lotgd',
            'SESSION_COOKIE_SAMESITE' => 'strict',
            'SESSION_COOKIE_SECURE_AUTO' => true,
        ]);

        $params = RuntimeHardening::buildSessionCookieParams($options, true);

        self::assertSame('/lotgd', $params['path']);
        self::assertSame('Strict', $params['samesite']);
        self::assertTrue($params['secure']);
        self::assertTrue($params['httponly']);
    }

    public function testBuildSessionCookieParamsForcesSecureWhenSameSiteNone(): void
    {
        $options = RuntimeHardening::buildOptions([
            'SESSION_COOKIE_SAMESITE' => 'None',
            'SESSION_COOKIE_SECURE_AUTO' => false,
            'SESSION_COOKIE_SECURE_FORCE' => false,
        ]);

        $params = RuntimeHardening::buildSessionCookieParams($options, false);

        self::assertSame('None', $params['samesite']);
        self::assertTrue($params['secure']);
    }

    public function testBuildHtmlHeadersIncludesHstsOnlyWhenHttps(): void
    {
        $options = RuntimeHardening::buildOptions([
            'SECURITY_HSTS_ENABLED' => true,
            'SECURITY_HSTS_INCLUDE_SUBDOMAINS' => true,
            'SECURITY_HSTS_PRELOAD' => true,
            'SECURITY_HSTS_MAX_AGE' => 3600,
        ]);

        $httpsHeaders = RuntimeHardening::buildHtmlHeaders($options, true);
        $httpHeaders = RuntimeHardening::buildHtmlHeaders($options, false);

        self::assertArrayHasKey('Strict-Transport-Security', $httpsHeaders);
        self::assertStringContainsString('max-age=3600', $httpsHeaders['Strict-Transport-Security']);
        self::assertStringContainsString('includeSubDomains', $httpsHeaders['Strict-Transport-Security']);
        self::assertStringContainsString('preload', $httpsHeaders['Strict-Transport-Security']);
        self::assertArrayNotHasKey('Strict-Transport-Security', $httpHeaders);
    }

    public function testIsHttpsRequestUnderstandsForwardedProto(): void
    {
        $options = RuntimeHardening::buildOptions();
        self::assertFalse(RuntimeHardening::isHttpsRequest([
            'HTTP_X_FORWARDED_PROTO' => 'https,http',
        ], $options));

        $trustedOptions = RuntimeHardening::buildOptions([
            'SECURITY_TRUST_FORWARDED_PROTO' => true,
        ]);
        self::assertTrue(RuntimeHardening::isHttpsRequest([
            'HTTP_X_FORWARDED_PROTO' => 'https,http',
            'REMOTE_ADDR' => '127.0.0.1',
        ], $trustedOptions));

        $allowlistedOptions = RuntimeHardening::buildOptions([
            'SECURITY_TRUST_FORWARDED_PROTO' => true,
            'SECURITY_TRUSTED_PROXIES' => '10.0.0.1',
        ]);
        self::assertFalse(RuntimeHardening::isHttpsRequest([
            'HTTP_X_FORWARDED_PROTO' => 'https',
            'REMOTE_ADDR' => '127.0.0.1',
        ], $allowlistedOptions));

        self::assertTrue(RuntimeHardening::isHttpsRequest([
            'HTTP_X_FORWARDED_PROTO' => 'https',
            'REMOTE_ADDR' => '10.0.0.1',
        ], $allowlistedOptions));

        self::assertFalse(RuntimeHardening::isHttpsRequest([
            'HTTP_X_FORWARDED_PROTO' => 'http',
            'HTTPS' => 'off',
            'SERVER_PORT' => '80',
        ], $trustedOptions));
    }

    /**
     * Without an explicit allowlist a forwarded protocol is only believed from
     * a peer that cannot be an ordinary visitor. Otherwise anyone could decide
     * whether their own session cookie carries the Secure flag.
     *
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('provideUnlistedProxyPeers')]
    public function testForwardedProtoWithoutAllowlistIsLimitedToPrivatePeers(
        string $remoteAddress,
        bool $expected
    ): void {
        $options = RuntimeHardening::buildOptions([
            'SECURITY_TRUST_FORWARDED_PROTO' => true,
        ]);

        self::assertSame($expected, RuntimeHardening::isHttpsRequest([
            'HTTP_X_FORWARDED_PROTO' => 'https',
            'HTTPS' => 'off',
            'SERVER_PORT' => '80',
            'REMOTE_ADDR' => $remoteAddress,
        ], $options));
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function provideUnlistedProxyPeers(): array
    {
        return [
            'loopback' => ['127.0.0.1', true],
            'container network' => ['172.18.0.5', true],
            'private class A' => ['10.1.2.3', true],
            'private class C' => ['192.168.1.10', true],
            'IPv6 loopback' => ['::1', true],
            'IPv6 unique local' => ['fd00::1', true],
            'public IPv4 client' => ['203.0.113.7', false],
            'public IPv6 client' => ['2001:db8::1', false],
            'missing address' => ['', false],
            'not an address' => ['not-an-ip', false],
        ];
    }

    /**
     * A proxy's address on a container network is rarely stable enough to
     * write down literally, so allowlist entries accept CIDR blocks.
     *
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('provideTrustedProxyAllowlists')]
    public function testTrustedProxyAllowlistSupportsLiteralsAndCidrBlocks(
        string $allowlist,
        string $remoteAddress,
        bool $expected
    ): void {
        $options = RuntimeHardening::buildOptions([
            'SECURITY_TRUST_FORWARDED_PROTO' => true,
            'SECURITY_TRUSTED_PROXIES' => $allowlist,
        ]);

        self::assertSame($expected, RuntimeHardening::isHttpsRequest([
            'HTTP_X_FORWARDED_PROTO' => 'https',
            'HTTPS' => 'off',
            'SERVER_PORT' => '80',
            'REMOTE_ADDR' => $remoteAddress,
        ], $options));
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: bool}>
     */
    public static function provideTrustedProxyAllowlists(): array
    {
        return [
            'literal hit' => ['10.0.0.1,10.0.0.2', '10.0.0.2', true],
            'literal miss' => ['10.0.0.1,10.0.0.2', '10.0.0.3', false],
            'IPv4 block hit' => ['172.18.0.0/16', '172.18.4.5', true],
            'IPv4 block miss' => ['172.18.0.0/16', '172.19.4.5', false],
            'narrow block hit' => ['10.0.0.0/31', '10.0.0.1', true],
            'narrow block miss' => ['10.0.0.0/31', '10.0.0.2', false],
            'mixed list' => ['127.0.0.1, 192.168.5.0/24', '192.168.5.77', true],
            'IPv6 block hit' => ['fd00::/8', 'fd00::abcd', true],
            'IPv6 spelled out' => ['fd00:0:0:0:0:0:0:1', 'fd00::1', true],
            'IPv4 peer against IPv6 block' => ['fd00::/8', '10.0.0.1', false],
            'malformed entry' => ['not-an-ip', '10.0.0.1', false],
            'malformed prefix' => ['10.0.0.0/999', '10.0.0.1', false],
            // An explicit list replaces the private-peer default entirely.
            'private peer outside the list' => ['10.0.0.1', '10.9.9.9', false],
        ];
    }

    public function testPrivilegeElevationSnapshotIsTracked(): void
    {
        $session = [
            'user' => [
                'superuser' => 8,
            ],
            'security' => [
                'superuser_snapshot' => 4,
            ],
        ];

        RuntimeHardening::regenerateOnPrivilegeElevation($session);

        self::assertSame(8, $session['security']['superuser_snapshot']);
    }

    public function testPrivilegeElevationReturnsFalseWithoutActiveSession(): void
    {
        $session = [
            'user' => [
                'superuser' => 8,
            ],
            'security' => [
                'superuser_snapshot' => 1,
            ],
        ];

        self::assertFalse(RuntimeHardening::regenerateOnPrivilegeElevation($session));
        self::assertSame(8, $session['security']['superuser_snapshot']);
    }
}
