<?php

declare(strict_types=1);

namespace Lotgd\Tests\Security;

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/modules/TwoFactorAuth/TwoFactorAuthService.php';

class TwoFactorAuthServiceTest extends TestCase
{
    public function testSetupEnableFlowAcceptsValidToken(): void
    {
        $secret = \TwoFactorAuthService::generateSecret();
        $now = 1700000000;
        $token = \TwoFactorAuthService::generateTokenAtTime($secret, 6, 30, $now);

        $result = \TwoFactorAuthService::verifyTotp($secret, $token, 6, 30, 1, 0, $now);

        $this->assertTrue($result['valid']);
        $this->assertSame('ok', $result['reason']);
        $this->assertGreaterThan(0, $result['timestep']);
    }

    public function testIncorrectTokenIsRejected(): void
    {
        $secret = \TwoFactorAuthService::generateSecret();
        $now = 1700000000;

        $result = \TwoFactorAuthService::verifyTotp($secret, '000000', 6, 30, 1, 0, $now);

        $this->assertFalse($result['valid']);
    }

    public function testReplayTokenIsRejectedUsingLastUsedStep(): void
    {
        $secret = \TwoFactorAuthService::generateSecret();
        $now = 1700000000;
        $token = \TwoFactorAuthService::generateTokenAtTime($secret, 6, 30, $now);
        $step = intdiv($now, 30);

        $result = \TwoFactorAuthService::verifyTotp($secret, $token, 6, 30, 1, $step, $now);

        $this->assertFalse($result['valid']);
    }

    public function testNavigationLockWhitelist(): void
    {
        $allowed = \TwoFactorAuthService::buildAllowedChallengeNavs('runmodule.php?module=twofactorauth&op=confirm_disable&token=abc');

        $this->assertTrue(\TwoFactorAuthService::isUriAllowed('runmodule.php?module=twofactorauth&op=challenge', $allowed));
        $this->assertTrue(\TwoFactorAuthService::isUriAllowed('runmodule.php?module=twofactorauth&op=challenge&c=1', $allowed));
        $this->assertTrue(\TwoFactorAuthService::isUriAllowed('runmodule.php?op=challenge&module=twofactorauth&c=1', $allowed));
        $this->assertFalse(\TwoFactorAuthService::isUriAllowed('village.php', $allowed));
    }

    public function testDisableTokenValidationAndExpiry(): void
    {
        $token = \TwoFactorAuthService::signDisableToken(123, 'test@example.com', 1700000300, 'secret-key');

        $valid = \TwoFactorAuthService::verifyDisableToken($token, 'secret-key', 1700000000);
        $expired = \TwoFactorAuthService::verifyDisableToken($token, 'secret-key', 1700000400);

        $this->assertTrue($valid['valid']);
        $this->assertSame(123, $valid['acctid']);
        $this->assertFalse($expired['valid']);
    }

    public function testQrCodeUrlBuilderIncludesPayloadAndSize(): void
    {
        $url = \TwoFactorAuthService::buildQrCodeUrl(
            'https://api.qrserver.com/v1/create-qr-code/',
            'otpauth://totp/Example',
            180
        );

        $query = [];
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        $this->assertSame('180x180', $query['size'] ?? null);
        $this->assertSame('otpauth://totp/Example', $query['data'] ?? null);
    }

    public function testNavigationLockWhitelistAcceptsSubdirectoryPath(): void
    {
        $allowed = \TwoFactorAuthService::buildAllowedChallengeNavs();

        $this->assertTrue(\TwoFactorAuthService::isUriAllowed('/lotgd/runmodule.php?module=twofactorauth&op=challenge', $allowed));
    }
}
