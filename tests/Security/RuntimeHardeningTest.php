<?php

declare(strict_types=1);

namespace Lotgd\Tests\Security;

use Lotgd\Security\RuntimeHardening;
use PHPUnit\Framework\TestCase;

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
